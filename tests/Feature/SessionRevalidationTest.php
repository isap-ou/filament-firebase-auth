<?php

use Filament\Http\Middleware\AuthenticateSession;
use Filament\Panel;
use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Http\Middleware\ScheduleFirebaseSessionCheck;
use Isapp\FilamentFirebaseAuth\Jobs\SyncFirebaseIdentity;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\UserNotFound;

use function Pest\Laravel\get;
use function Pest\Laravel\travel;
use function Pest\Laravel\withCookie;

require_once __DIR__ . '/../Support/SessionCheckFixtures.php';

it('does not dispatch inside the interval', function () {
    Bus::fake();
    $user = linkedUser();
    sessionCheckGuard()->login($user);

    get('/app')->assertOk();
    travel(10)->minutes();
    get('/app')->assertOk();

    Bus::assertDispatchedTimes(SyncFirebaseIdentity::class, 1);
    expect(session('filament-firebase-auth.checked_at.app'))->not->toBeNull();

    travel(6)->minutes();
    get('/app')->assertOk();

    Bus::assertDispatchedTimes(SyncFirebaseIdentity::class, 2);
});

it('dispatches after the response by default', function () {
    Bus::fake();
    $user = linkedUser();
    sessionCheckGuard()->login($user);

    get('/app')->assertOk();

    Bus::assertDispatchedAfterResponse(
        SyncFirebaseIdentity::class,
        fn (SyncFirebaseIdentity $job): bool => $job->identityId === $user->firebaseIdentity?->getKey(),
    );
});

it('dispatches to the configured queue', function () {
    config()->set('filament-firebase-auth.revalidate_queue', 'auth');
    Queue::fake();
    sessionCheckGuard()->login(linkedUser());

    get('/app')->assertOk();

    Queue::assertPushedOn('auth', SyncFirebaseIdentity::class);
});

it('never calls firebase in the request', function () {
    Bus::fake();
    mockFirebase()->shouldNotReceive('getUser');
    sessionCheckGuard()->login(linkedUser());

    get('/app')->assertOk();

    Bus::assertDispatchedAfterResponse(SyncFirebaseIdentity::class);
});

it('marks a deleted firebase account as invalidated', function () {
    $user = linkedUser();
    $fingerprint = $user->getAuthPassword();
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andThrow(new UserNotFound('No user.'));

    sessionCheckRunJob($user);

    $user->refresh();
    expect($user->firebaseIdentity?->invalidated_at)->not->toBeNull()
        ->and($user->getAuthPassword())->not->toBe($fingerprint);
});

it('marks a disabled firebase account as invalidated', function () {
    $user = linkedUser();
    $fingerprint = $user->getAuthPassword();
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['disabled' => true]));

    sessionCheckRunJob($user);

    $user->refresh();
    expect($user->firebaseIdentity?->invalidated_at)->not->toBeNull()
        ->and($user->getAuthPassword())->not->toBe($fingerprint);
});

it('stores revoked tokens time', function () {
    $user = linkedUser();
    $user->firebaseIdentity?->update(['tokens_valid_after' => now()->subHour()]);
    $fingerprint = $user->refresh()->getAuthPassword();
    $revokedAt = now()->addMinute()->startOfSecond();
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord([
        'displayName' => 'Jane Doe',
        'validSince' => (string) $revokedAt->getTimestamp(),
    ]));

    sessionCheckRunJob($user);

    $user->refresh();
    expect($user->firebaseIdentity?->tokens_valid_after?->getTimestamp())->toBe($revokedAt->getTimestamp())
        ->and($user->firebaseIdentity?->invalidated_at)->toBeNull()
        ->and($user->getAttribute('name'))->toBe('Jane Doe')
        ->and($user->getAuthPassword())->not->toBe($fingerprint);
});

it('clears the mark when the firebase account is enabled again', function () {
    $user = linkedUser();
    $user->firebaseIdentity?->update(['invalidated_at' => now()->subDay()]);
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());

    sessionCheckRunJob($user);

    expect($user->refresh()->firebaseIdentity?->invalidated_at)->toBeNull();
});

it('logs the user out on the next request after a change', function () {
    // No fake: the middleware dispatches the job, and the job runs after the response.
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['disabled' => true]));
    $user = linkedUser();
    sessionCheckGuard()->login($user);

    get('/app')->assertOk();

    expect($user->refresh()->firebaseIdentity?->invalidated_at)->not->toBeNull();

    sessionCheckNextRequest();
    get('/app')->assertRedirect('/app/login');

    expect(auth('app')->guest())->toBeTrue();
});

it('logs out a remember me session too', function () {
    Bus::fake();
    $user = linkedUser();
    $guard = sessionCheckGuard();
    $guard->login($user, remember: true);
    $recaller = $guard->getRecallerName();
    $cookie = app('cookie')->queued($recaller)?->getValue();

    expect($cookie)->toBeString();

    // Only the cookie signs the user in: the session is gone.
    session()->flush();
    sessionCheckNextRequest();
    withCookie($recaller, (string) $cookie)->get('/app')->assertOk();

    expect(auth('app')->viaRemember())->toBeTrue();

    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['disabled' => true]));
    sessionCheckRunJob($user);

    session()->flush();
    sessionCheckNextRequest();
    withCookie($recaller, (string) $cookie)->get('/app')->assertRedirect('/app/login');

    expect(auth('app')->guest())->toBeTrue();
});

it('changes nothing when firebase is unreachable', function () {
    $log = Log::spy();
    $user = linkedUser();
    $before = $user->firebaseIdentity?->getAttributes();
    $fingerprint = $user->getAuthPassword();
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')
        ->andThrow(new ApiConnectionFailed('Connection failed for key=secret-api-key'));

    travel(1)->minute();
    sessionCheckRunJob($user);

    $user->refresh();
    expect($user->firebaseIdentity?->getAttributes())->toBe($before)
        ->and($user->getAuthPassword())->toBe($fingerprint);
    $log->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => ($context['exception'] ?? null) === ApiConnectionFailed::class
            && ! str_contains($message . json_encode($context), 'secret-api-key'),
    );
});

it('adds AuthenticateSession to a panel that lacks it', function () {
    $panel = Panel::make()->id('bare')->plugin(FilamentFirebaseAuthPlugin::make());

    expect($panel->getMiddleware())->toContain(AuthenticateSession::class)
        ->and($panel->getAuthMiddleware())->toContain(ScheduleFirebaseSessionCheck::class);

    $withFilament = Panel::make()->id('with-filament')
        ->middleware([AuthenticateSession::class])
        ->plugin(FilamentFirebaseAuthPlugin::make());
    $withLaravel = Panel::make()->id('with-laravel')
        ->middleware([LaravelAuthenticateSession::class])
        ->plugin(FilamentFirebaseAuthPlugin::make());

    expect(array_count_values($withFilament->getMiddleware())[AuthenticateSession::class])->toBe(1)
        ->and($withLaravel->getMiddleware())->not->toContain(AuthenticateSession::class);
});

it('is off when the interval is zero', function () {
    config()->set('filament-firebase-auth.revalidate_every', 0);
    Bus::fake();
    sessionCheckGuard()->login(linkedUser());

    get('/app')->assertOk();

    Bus::assertNothingDispatched();
    expect(session()->has('filament-firebase-auth.checked_at.app'))->toBeFalse();
});
