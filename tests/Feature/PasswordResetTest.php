<?php

use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\Password;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Passwords\FirebasePasswordBroker;
use Isapp\FilamentFirebaseAuth\Passwords\FirebasePasswordBrokerManager;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

// The token repository hashes tokens with the app key, and the Livewire pages encrypt with it.
beforeEach(function () {
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
});

/**
 * Opens Filament's own "forgot password" page of the open fixture panel and submits the email.
 */
function requestResetLink(string $email = 'jane@example.com'): Testable
{
    Filament::setCurrentPanel('app');

    return livewire(RequestPasswordReset::class)
        ->fillForm(['email' => $email])
        ->call('request');
}

/**
 * Opens Filament's own reset page of the open fixture panel and submits a new password.
 */
function submitNewPassword(string $token, string $password = 'new-secret-123', string $email = 'jane@example.com'): Testable
{
    Filament::setCurrentPanel('app');

    return livewire(ResetPassword::class, ['email' => $email, 'token' => $token])
        ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
        ->call('resetPassword');
}

/**
 * The notification that Filament's page shows after it sent a reset link.
 */
function resetLinkSentNotification(): Notification
{
    return Notification::make()
        ->title(__(Password::RESET_LINK_SENT))
        ->body(__('filament-panels::auth/pages/password-reset/request-password-reset.notifications.sent.body'))
        ->success();
}

/**
 * Creates a reset token for the record through the broker of the open fixture panel.
 */
function createResetToken(User $user): string
{
    $broker = Password::broker('users_app');

    if (! $broker instanceof PasswordBroker) {
        throw new RuntimeException('The [users_app] broker is not a Laravel password broker.');
    }

    return $broker->createToken($user);
}

function resetTokenCount(): int
{
    return DB::table('password_reset_tokens')->count();
}

it('sends the filament reset notification', function () {
    Notifications::fake();
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->with('jane@example.com')->andReturn(userRecord());
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertSentTo($user, ResetPasswordNotification::class);
    expect(resetTokenCount())->toBe(1);
});

it('sends a link to a record with the same email and no link, also when the email is not verified', function () {
    Notifications::fake();
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertSentTo($user, ResetPasswordNotification::class);
    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('does not reveal unknown emails', function () {
    Notifications::fake();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andThrow(new UserNotFound('No user'));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    requestResetLink('nobody@example.com')
        ->assertNotified(resetLinkSentNotification())
        ->assertNotNotified(__(Password::INVALID_USER));

    Notifications::assertNothingSent();
    expect(resetTokenCount())->toBe(0);
});

it('does not reveal a firebase account without a local record', function () {
    Notifications::fake();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertNothingSent();
    expect(User::query()->count())->toBe(0)
        ->and(resetTokenCount())->toBe(0);
});

it('does not send to google only accounts', function () {
    Notifications::fake();
    linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord([
        'providerUserInfo' => [['providerId' => 'google.com', 'rawId' => 'g-1', 'email' => 'jane@example.com']],
    ]));

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertNothingSent();
    expect(resetTokenCount())->toBe(0);
});

it('does not send to a record linked to another uid', function () {
    Notifications::fake();
    linkedUser(uid: 'uid-old');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['localId' => 'uid-new']));

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertNothingSent();
});

it('does not send to a disabled firebase account', function () {
    Notifications::fake();
    linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['disabled' => true]));

    requestResetLink()->assertNotified(resetLinkSentNotification());

    Notifications::assertNothingSent();
});

it('sets the new password in firebase and verifies the email', function () {
    $user = linkedUser(verified: false);
    $token = createResetToken($user);
    $validSince = now()->addMinute()->startOfSecond();

    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->with('jane@example.com')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()->with('uid-1', ['emailVerified' => true])->andReturn(userRecord());
    $firebase->shouldReceive('changeUserPassword')->once()->with('uid-1', 'new-secret-123')
        ->andReturn(userRecord(['validSince' => (string) $validSince->getTimestamp()]));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    submitNewPassword($token)
        ->assertHasNoFormErrors()
        ->assertNotified(__(Password::PASSWORD_RESET))
        ->assertRedirect();

    $identity = $user->refresh()->firebaseIdentity;

    expect($identity?->email_verified_at)->not->toBeNull()
        ->and($identity?->tokens_valid_after?->getTimestamp())->toBe($validSince->getTimestamp())
        ->and($user->refresh()->getAttributes()['password'])->toBeNull()
        ->and(FirebaseIdentity::query()->count())->toBe(1)
        ->and(resetTokenCount())->toBe(0);
});

it('links an unlinked record after a reset', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $token = createResetToken($user);

    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()->with('uid-1', ['emailVerified' => true])->andReturn(userRecord());
    $firebase->shouldReceive('changeUserPassword')->once()->with('uid-1', 'new-secret-123')->andReturn(userRecord());

    submitNewPassword($token)->assertHasNoFormErrors()->assertNotified(__(Password::PASSWORD_RESET));

    $identity = $user->refresh()->firebaseIdentity;

    expect($identity?->authenticatable_id)->toBe($user->getKey())
        ->and($identity?->email_verified_at)->not->toBeNull()
        ->and($user->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and(FirebaseIdentity::query()->count())->toBe(1)
        ->and(resetTokenCount())->toBe(0);
});

it('changes nothing in firebase for a wrong token', function () {
    $user = linkedUser();
    createResetToken($user);

    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldNotReceive('changeUserPassword');
    $firebase->shouldNotReceive('updateUser');

    submitNewPassword('wrong-token')->assertNotified(__(Password::INVALID_TOKEN));

    expect(resetTokenCount())->toBe(1);
});

it('keeps the default broker for other drivers', function () {
    expect(app('auth.password'))->toBeInstanceOf(FirebasePasswordBrokerManager::class)
        ->and(app(PasswordBrokerFactory::class))->toBe(app('auth.password'))
        ->and(Password::broker('users_app'))->toBeInstanceOf(FirebasePasswordBroker::class)
        ->and(Password::broker('admins'))->toBeInstanceOf(FirebasePasswordBroker::class)
        ->and(Password::broker('users'))->toBeInstanceOf(PasswordBroker::class)
        ->and(Password::broker('users'))->not->toBeInstanceOf(FirebasePasswordBroker::class)
        ->and(app('auth.password.broker'))->not->toBeInstanceOf(FirebasePasswordBroker::class);
});
