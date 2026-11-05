<?php

use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use Isapp\FilamentFirebaseAuth\Tests\Emulator\Emulator;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

// These tests use the real `kreait` client against the Firebase Auth Emulator. Nothing mocks Firebase.

function emulator(): Emulator
{
    return app(Emulator::class);
}

/**
 * Opens Filament's own login page of a fixture panel and submits the email and password.
 */
function emulatorSignIn(string $panel, string $email, string $password): Testable
{
    Filament::setCurrentPanel($panel);

    return livewire(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');
}

/**
 * One test shares one container between its Livewire calls and HTTP requests. In an app,
 * each request gets a new container. The Firebase read actions remember their answers for
 * one request (`scoped`), so a later request of the same test forgets them first.
 */
function emulatorNewRequest(): void
{
    app()->forgetInstance(FindFirebaseUser::class);
    app()->forgetInstance(FindFirebaseUserByEmail::class);
    app()->forgetInstance(CheckFirebasePassword::class);
}

it('signs in with email and password and creates the record', function () {
    $firebaseUser = emulator()->firebase()->createUser([
        'email' => 'jane@example.com',
        'password' => 'Secret123!',
        'displayName' => 'Jane',
    ]);

    emulatorSignIn('app', 'jane@example.com', 'Secret123!')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $user = User::query()->sole();

    expect($user->getAttribute('email'))->toBe('jane@example.com')
        ->and($user->getAttribute('name'))->toBe('Jane')
        ->and(FirebaseIdentity::query()->sole()->firebase_uid)->toBe($firebaseUser->uid)
        ->and(DB::table('users')->value('password'))->toBeNull()
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('rejects a wrong password with the generic message', function () {
    emulator()->firebase()->createUser(['email' => 'jane@example.com', 'password' => 'Secret123!']);

    emulatorSignIn('app', 'jane@example.com', 'Wrong123!')
        ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('app')->check())->toBeFalse();
});

it('registers in firebase and locally', function () {
    Notification::fake();
    Filament::setCurrentPanel('app');

    livewire(Register::class)
        ->fillForm([
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Secret123!',
            'passwordConfirmation' => 'Secret123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $firebaseUser = emulator()->firebase()->getUserByEmail('new@example.com');
    $user = User::query()->where('email', 'new@example.com')->sole();

    expect($firebaseUser->displayName)->toBe('New User')
        ->and($firebaseUser->emailVerified)->toBeFalse()
        ->and($user->firebaseIdentity?->firebase_uid)->toBe($firebaseUser->uid)
        ->and(DB::table('users')->where('email', 'new@example.com')->value('password'))->toBeNull()
        ->and(emulator()->acceptsPassword('new@example.com', 'Secret123!'))->toBeTrue()
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('changes the password in firebase through the reset flow', function () {
    // A record with the same email and no link, and an unverified Firebase account:
    // the reset proves the email, so it links the record and verifies the email.
    Notification::fake();
    $firebaseUser = emulator()->firebase()->createUser(['email' => 'jane@example.com', 'password' => 'Old-secret-123']);
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    Filament::setCurrentPanel('app');

    livewire(RequestPasswordReset::class)
        ->fillForm(['email' => 'jane@example.com'])
        ->call('request');

    $url = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    livewire(ResetPassword::class, ['email' => 'jane@example.com', 'token' => $query['token'] ?? null])
        ->fillForm(['password' => 'New-secret-456', 'passwordConfirmation' => 'New-secret-456'])
        ->call('resetPassword')
        ->assertHasNoFormErrors();

    expect(emulator()->acceptsPassword('jane@example.com', 'New-secret-456'))->toBeTrue()
        ->and(emulator()->acceptsPassword('jane@example.com', 'Old-secret-123'))->toBeFalse()
        ->and(emulator()->firebase()->getUser($firebaseUser->uid)->emailVerified)->toBeTrue()
        ->and($user->refresh()->firebaseIdentity?->firebase_uid)->toBe($firebaseUser->uid);

    emulatorNewRequest();
    emulatorSignIn('app', 'jane@example.com', 'New-secret-456')->assertHasNoFormErrors();

    expect(auth('app')->id())->toBe($user->getKey());
});

it('sets the verified flag in firebase from the filament verification link', function () {
    $firebaseUser = emulator()->firebase()->createUser(['email' => 'jane@example.com', 'password' => 'Secret123!']);
    $user = linkedUser(verified: false, uid: $firebaseUser->uid);
    Filament::setCurrentPanel('app');

    actingAs($user, 'app')->get(Filament::getVerifyEmailUrl($user))->assertRedirect();

    expect(emulator()->firebase()->getUser($firebaseUser->uid)->emailVerified)->toBeTrue()
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->not->toBeNull();
});

it('changes the email in firebase after the email change link', function () {
    Notification::fake();
    $firebaseUser = emulator()->firebase()->createUser([
        'email' => 'jane@example.com',
        'password' => 'Secret123!',
        'emailVerified' => true,
    ]);
    $user = linkedUser(uid: $firebaseUser->uid);
    Filament::setCurrentPanel('app');
    actingAs($user, 'app');

    livewire(EditProfile::class)
        ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'Secret123!'])
        ->call('save')
        ->assertHasNoFormErrors();

    $url = null;
    Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    // Nothing changes before the person opens the link.
    expect(emulator()->firebase()->getUser($firebaseUser->uid)->email)->toBe('jane@example.com');

    // The person opens the link later, in a new request. The session check of that request reads Firebase again.
    emulatorNewRequest();
    actingAs($user, 'app')->get((string) $url)->assertRedirect();

    $changed = emulator()->firebase()->getUser($firebaseUser->uid);

    expect($changed->email)->toBe('new@example.com')
        ->and($changed->emailVerified)->toBeTrue()
        ->and($user->refresh()->getAttribute('email'))->toBe('new@example.com')
        ->and(emulator()->acceptsPassword('new@example.com', 'Secret123!'))->toBeTrue();
});

it('creates the firebase account and the record with artisan', function () {
    artisan('make:filament-user', [
        '--panel' => 'admin',
        '--name' => 'Ada',
        '--email' => 'ada@example.com',
        '--password' => 'Secret123!',
    ])->assertSuccessful();

    $firebaseUser = emulator()->firebase()->getUserByEmail('ada@example.com');
    $admin = Admin::query()->where('email', 'ada@example.com')->sole();

    expect($firebaseUser->displayName)->toBe('Ada')
        ->and($firebaseUser->emailVerified)->toBeTrue()
        ->and($admin->firebaseIdentity?->firebase_uid)->toBe($firebaseUser->uid)
        ->and(DB::table('admins')->where('email', 'ada@example.com')->value('password'))->toBeNull();

    // The closed panel accepts the linked record.
    emulatorNewRequest();
    emulatorSignIn('admin', 'ada@example.com', 'Secret123!')->assertHasNoFormErrors();

    expect(auth('admin')->id())->toBe($admin->getKey());
});

describe('new_only deletion', function () {
    beforeEach(function () {
        config()->set('filament-firebase-auth.delete_accounts', 'new_only');
    });

    it('deletes an account that was created for this sign-in and then blocked', function () {
        // The Firebase JS SDK creates an account through the public sign-up endpoint.
        $uid = emulator()->signUpThroughRest('new@example.com', 'Secret123!');

        emulatorSignIn('admin', 'new@example.com', 'Secret123!')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);

        expect(fn () => emulator()->firebase()->getUser($uid))->toThrow(UserNotFound::class)
            ->and(Admin::query()->count())->toBe(0)
            ->and(FirebaseIdentity::query()->count())->toBe(0);
    });

    it('keeps an account that is older than the window', function () {
        $uid = emulator()->signUpThroughRest('old@example.com', 'Secret123!');
        emulator()->setCreatedAt($uid, now()->subHours(2));

        expect(emulator()->firebase()->getUser($uid)->metadata->createdAt->getTimestamp())
            ->toBeLessThan(now()->subHour()->getTimestamp());

        emulatorSignIn('admin', 'old@example.com', 'Secret123!')
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);

        $kept = emulator()->firebase()->getUser($uid);

        // The blocked sign-in reached Firebase: the emulator moved `lastLoginAt` to now.
        expect($kept->email)->toBe('old@example.com')
            ->and($kept->metadata->lastLoginAt?->getTimestamp())->toBeGreaterThan(now()->subMinute()->getTimestamp())
            ->and(Admin::query()->count())->toBe(0);
    });
});
