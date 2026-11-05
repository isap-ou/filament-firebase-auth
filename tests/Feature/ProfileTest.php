<?php

use Filament\Auth\Http\Requests\EmailChangeVerificationRequest as FilamentEmailChangeVerificationRequest;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\RenewCurrentSession;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Http\Requests\EmailChangeVerificationRequest;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Kreait\Firebase\Exception\Auth\WeakPassword;
use League\Uri\Components\Query;
use Livewire\Features\SupportTesting\Testable;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;
use function Pest\Livewire\livewire;

/**
 * Opens the plugin profile page of a fixture panel as the given record.
 */
function profilePage(Model & Authenticatable $user, string $panel = 'app'): Testable
{
    Filament::setCurrentPanel($panel);
    actingAs($user, $panel);

    return livewire(EditProfile::class);
}

/**
 * Returns the session guard of a fixture panel.
 */
function profileGuard(string $guard = 'app'): SessionGuard
{
    $sessionGuard = auth()->guard($guard);

    if (! $sessionGuard instanceof SessionGuard) {
        throw new RuntimeException("The guard [{$guard}] is not a session guard.");
    }

    return $sessionGuard;
}

/**
 * Returns the session value that Laravel's AuthenticateSession compares with the fingerprint.
 */
function profileSessionHash(Model & Authenticatable $user, string $guard = 'app'): string
{
    return profileGuard($guard)->hashPasswordForCookie($user->getAuthPassword());
}

/**
 * Mocks Firebase for a password user whose current password is "secret".
 */
function profileFirebase(): MockInterface
{
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'secret')
        ->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'wrong')
        ->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));

    return $firebase;
}

/**
 * Creates a saved fixture admin with a saved link to uid-1.
 */
function profileLinkedAdmin(): Admin
{
    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    $admin->firebaseIdentity()->create([
        'firebase_uid' => 'uid-1',
        'email_verified_at' => now(),
        'synced_at' => now(),
    ]);

    return $admin->load('firebaseIdentity');
}

/**
 * Sends the email change on the profile page and returns the verification URL from the mail.
 */
function profileRequestEmailChange(User $user, string $newEmail): string
{
    Notification::fake();

    profilePage($user)
        ->fillForm(['email' => $newEmail, 'currentPassword' => 'secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    $url = null;

    Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    expect($url)->toBeString();

    return (string) $url;
}

// The background session check calls Firebase after a panel request. These tests mock Firebase strictly,
// so they turn the check off. SessionRevalidationTest covers the check.
beforeEach(function () {
    config()->set('filament-firebase-auth.revalidate_every', 0);
});

it('checks the current password with firebase', function () {
    $user = linkedUser();
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('changeUserPassword');

    profilePage($user)
        ->fillForm([
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'wrong',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword' => __('validation.current_password')]);
});

it('refuses a current password that signs in to another firebase user', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord());
    $firebase->shouldReceive('getUser')->with('uid-2')->andReturn(userRecord(['localId' => 'uid-2']));
    $firebase->shouldReceive('signInWithEmailAndPassword')->andReturn(signInResult('uid-2'));
    $firebase->shouldNotReceive('changeUserPassword');

    profilePage($user)
        ->fillForm([
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'secret',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);
});

it('changes the password in firebase only', function () {
    $user = linkedUser();
    $firebase = profileFirebase();
    $validSince = now()->addMinute()->startOfSecond();
    $firebase->shouldReceive('changeUserPassword')->once()->with('uid-1', 'new-secret-123')
        ->andReturn(userRecord(['validSince' => (string) $validSince->getTimestamp()]));
    // Livewire test requests skip the middleware and have no session. Filament writes the
    // password to the session only when the request has one (`EditProfile::save()`).
    app()->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

    profilePage($user)
        ->fillForm([
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'secret',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect(DB::table('users')->value('password'))->toBeNull()
        ->and($user->firebaseIdentity?->tokens_valid_after?->getTimestamp())->toBe($validSince->getTimestamp())
        // The current session keeps the new fingerprint, not a hash of the new password.
        ->and(session('password_hash_app'))->toBe(profileSessionHash($user))
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('changes the email in firebase without verification', function () {
    $admin = profileLinkedAdmin();
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('changeUserEmail');
    // The new email is not proven, so Firebase must not keep the verified flag.
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'new@example.com', 'emailVerified' => false])
        ->andReturn(userRecord(['email' => 'new@example.com', 'emailVerified' => false]));

    profilePage($admin, 'admin')
        ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    $admin->refresh();

    expect($admin->getAttribute('email'))->toBe('new@example.com')
        ->and($admin->firebaseIdentity?->email_verified_at)->toBeNull()
        ->and(session('password_hash_admin'))->toBe(profileSessionHash($admin, 'admin'));
});

it('shows a form error when the new email exists in firebase', function () {
    $admin = profileLinkedAdmin();
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->once()->andThrow(new EmailExists('EMAIL_EXISTS'));

    profilePage($admin, 'admin')
        ->fillForm(['email' => 'taken@example.com', 'currentPassword' => 'secret'])
        ->call('save')
        ->assertHasFormErrors(['email' => __('filament-firebase-auth::profile.messages.email_exists')]);

    expect($admin->refresh()->getAttribute('email'))->toBe('jane@example.com');
});

it('changes the email in firebase after the verification link', function () {
    $user = linkedUser(verified: false);
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'new@example.com', 'emailVerified' => true])
        ->andReturn(userRecord(['email' => 'new@example.com']));
    // The plugin request does not run Filament's `fulfill()`, so no second Firebase call marks the email.
    $firebase->shouldNotReceive('changeUserEmail');

    $url = profileRequestEmailChange($user, 'new@example.com');

    expect($user->refresh()->getAttribute('email'))->toBe('jane@example.com');

    actingAs($user, 'app')->get($url)->assertRedirect();

    $user->refresh();

    expect($user->getAttribute('email'))->toBe('new@example.com')
        ->and($user->firebaseIdentity?->email_verified_at)->not->toBeNull();
});

it('rolls back the email when firebase refuses it', function () {
    $user = linkedUser(verified: false);
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'new@example.com', 'emailVerified' => true])
        ->andThrow(new EmailExists('EMAIL_EXISTS'));

    $url = profileRequestEmailChange($user, 'new@example.com');

    actingAs($user, 'app')->get($url)->assertRedirect();

    $user->refresh();

    // Nothing marks the old email as verified: the person proved only the new email.
    expect($user->getAttribute('email'))->toBe('jane@example.com')
        ->and($user->firebaseIdentity?->email_verified_at)->toBeNull();
});

it('gives the filament controller the plugin email change request', function () {
    $user = linkedUser();
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->andReturn(userRecord(['email' => 'new@example.com']));

    $url = profileRequestEmailChange($user, 'new@example.com');

    $resolved = null;
    app()->afterResolving(FilamentEmailChangeVerificationRequest::class, function (object $request) use (&$resolved): void {
        $resolved = $request::class;
    });

    actingAs($user, 'app')->get($url)->assertRedirect();

    expect($resolved)->toBe(EmailChangeVerificationRequest::class);
});

it('hides password fields for google only users', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord([
        'providerUserInfo' => [
            ['providerId' => 'google.com', 'rawId' => 'google-1', 'email' => 'jane@example.com'],
        ],
    ]));
    $firebase->shouldNotReceive('updateUser');
    $firebase->shouldNotReceive('changeUserEmail');
    $firebase->shouldNotReceive('changeUserPassword');

    profilePage($user)
        ->assertFormFieldHidden('password')
        ->assertFormFieldHidden('currentPassword')
        ->assertFormFieldDisabled('email')
        // A changed request still never changes the email or the password.
        ->fillForm(['email' => 'other@example.com', 'password' => 'new-secret-123', 'passwordConfirmation' => 'new-secret-123'])
        ->call('save');

    expect($user->refresh()->getAttribute('email'))->toBe('jane@example.com');
});

it('deletes the account and applies the policy', function (string $mode, bool $deletes) {
    config()->set('filament-firebase-auth.delete_accounts', $mode);
    $user = linkedUser();
    $user->forceFill(['remember_token' => 'token'])->save();
    $firebase = profileFirebase();

    if ($deletes) {
        $firebase->shouldReceive('deleteUser')->once()->with('uid-1');
    } else {
        $firebase->shouldNotReceive('deleteUser');
    }

    profilePage($user)
        ->callAction('deleteAccount', data: ['currentPassword' => 'secret'])
        ->assertHasNoActionErrors()
        ->assertRedirect(Filament::getLoginUrl());

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('app')->check())->toBeFalse();
})->with([
    'orphaned' => ['orphaned', true],
    'never' => ['never', false],
]);

it('runs the deletion policy once with the user reason', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $user = linkedUser();
    profileFirebase();
    $policy = Mockery::mock(DecidesAccountDeletion::class);
    $policy->shouldReceive('shouldDelete')->once()
        ->withArgs(fn ($firebaseUser, DeletionReason $reason): bool => $reason === DeletionReason::UserDeletedAccount)
        ->andReturn(false);
    app()->instance(DecidesAccountDeletion::class, $policy);

    profilePage($user)
        ->callAction('deleteAccount', data: ['currentPassword' => 'secret'])
        ->assertHasNoActionErrors();

    expect(User::query()->count())->toBe(0);
});

it('needs the current password to delete the account', function () {
    $user = linkedUser();
    profileFirebase();

    profilePage($user)
        ->callAction('deleteAccount', data: ['currentPassword' => 'wrong'])
        ->assertHasActionErrors(['currentPassword']);

    expect(User::query()->count())->toBe(1)
        ->and(auth('app')->check())->toBeTrue();
});

it('logs out other sessions but keeps the current one', function () {
    $user = linkedUser();
    profileFirebase();
    $guard = profileGuard();
    $oldHash = profileSessionHash($user);

    profilePage($user)
        ->callAction('logoutOtherSessions', data: ['currentPassword' => 'secret'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament-firebase-auth::profile.actions.logout_other_sessions.notifications.done'));

    $newHash = session('password_hash_app');

    expect($newHash)->not->toBe($oldHash)
        ->and($newHash)->toBe(profileSessionHash($user->refresh()));

    // Another session still holds the old fingerprint.
    auth()->forgetGuards();
    session()->flush();
    withSession([$guard->getName() => $user->getKey(), 'password_hash_app' => $oldHash])
        ->get('/app')
        ->assertRedirect(Filament::getPanel('app')->getLoginUrl());

    // The current session holds the new fingerprint.
    auth()->forgetGuards();
    session()->flush();
    withSession([$guard->getName() => $user->getKey(), 'password_hash_app' => $newHash])
        ->get('/app')
        ->assertOk();
});

it('does not claim to log out other sessions without a link', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    mockFirebase();

    profilePage($user)
        ->callAction('logoutOtherSessions')
        ->assertNotified(__('filament-firebase-auth::profile.messages.no_link'));

    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('hides log out everywhere unless the config allows it', function () {
    $user = linkedUser();
    profileFirebase();

    profilePage($user)->assertActionHidden('logoutEverywhere');

    config()->set('filament-firebase-auth.global_logout', true);

    profilePage($user)->assertActionVisible('logoutEverywhere');
});

it('revokes firebase tokens on log out everywhere', function () {
    config()->set('filament-firebase-auth.global_logout', true);
    $user = linkedUser();
    $validSince = now()->addMinute()->startOfSecond();
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'secret')
        ->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('revokeRefreshTokens')->once()->with('uid-1');
    // The page reads the user first. The action reads it again after the revocation.
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(
        userRecord(),
        userRecord(['validSince' => (string) $validSince->getTimestamp()]),
    );

    profilePage($user)
        ->callAction('logoutEverywhere', data: ['currentPassword' => 'secret'])
        ->assertHasNoActionErrors();

    $user->refresh();

    expect($user->firebaseIdentity?->tokens_valid_after?->getTimestamp())->toBe($validSince->getTimestamp())
        ->and(session('password_hash_app'))->toBe(profileSessionHash($user))
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('renders the account actions on the profile page', function () {
    $user = linkedUser();
    profileFirebase();
    Filament::setCurrentPanel('app');

    actingAs($user, 'app')
        ->get('/app/profile')
        ->assertOk()
        ->assertSee(__('filament-firebase-auth::profile.actions.logout_other_sessions.label'))
        ->assertSee(__('filament-firebase-auth::profile.actions.delete_account.label'));
});

it('refuses the email change after the block link', function () {
    $user = linkedUser(verified: false);
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('updateUser');

    Notification::fake();

    profilePage($user)
        ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    $verifyUrl = null;
    $blockUrl = null;

    Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$verifyUrl): bool {
        $verifyUrl = $notification->url;

        return true;
    });
    Notification::assertSentTo($user, NoticeOfEmailChangeRequest::class, function (NoticeOfEmailChangeRequest $notification) use (&$blockUrl): bool {
        $blockUrl = $notification->blockVerificationUrl;

        return true;
    });

    actingAs($user, 'app')->get((string) $blockUrl)->assertRedirect();
    actingAs($user, 'app')->get((string) $verifyUrl)->assertForbidden();

    expect($user->refresh()->getAttribute('email'))->toBe('jane@example.com');
});

it('uses up the verification link when firebase refuses the email', function () {
    $user = linkedUser(verified: false);
    $firebase = profileFirebase();
    // Only the first click reaches Firebase. The second click finds no signature.
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'new@example.com', 'emailVerified' => true])
        ->andThrow(new ApiConnectionFailed('Firebase is down'));

    $url = profileRequestEmailChange($user, 'new@example.com');

    actingAs($user, 'app')->get($url)->assertRedirect();

    expect($user->refresh()->getAttribute('email'))->toBe('jane@example.com');

    // The owner of the old email may have blocked the change meanwhile, so the link never comes back.
    actingAs($user, 'app')->get($url)->assertForbidden();

    expect($user->refresh()->getAttribute('email'))->toBe('jane@example.com')
        ->and($user->firebaseIdentity?->email_verified_at)->toBeNull();
});

it('logs a failed read after the revocation on log out everywhere', function () {
    config()->set('filament-firebase-auth.global_logout', true);
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'secret')
        ->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('revokeRefreshTokens')->once()->with('uid-1');
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord())->once();
    $firebase->shouldReceive('getUser')->with('uid-1')->andThrow(new ApiConnectionFailed('Firebase is down'));
    $log = Log::spy();

    profilePage($user)
        ->callAction('logoutEverywhere', data: ['currentPassword' => 'secret'])
        ->assertNotified(__('filament-firebase-auth::profile.messages.firebase_failed'));

    $log->shouldHaveReceived('warning')->with('Firebase could not read the user after the revocation.', Mockery::any())->once();
    $log->shouldNotHaveReceived('warning', ['Firebase could not revoke the tokens.', Mockery::any()]);

    expect(auth('app')->id())->toBe($user->getKey());
});

it('uses filament for a record without a link', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('updateUser');
    Filament::setCurrentPanel('app');
    $url = Filament::getVerifyEmailChangeUrl($user, 'new@example.com');
    $signature = Query::new($url)->get('signature');
    // Filament 5.10.2 and later read the prefixed key. Earlier 5.x versions read the plain signature.
    cache()->put("filament:auth:email_change:verification:{$signature}", true, ttl: now()->addHour());
    cache()->put($signature, true, ttl: now()->addHour());

    actingAs($user, 'app')->get($url)->assertRedirect();

    expect($user->refresh()->getAttribute('email'))->toBe('new@example.com');
});

it('changes the email back in firebase when the password change fails', function () {
    $admin = profileLinkedAdmin();
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'new@example.com', 'emailVerified' => false])
        ->andReturn(userRecord(['email' => 'new@example.com', 'emailVerified' => false]));
    $firebase->shouldReceive('changeUserPassword')->once()->andThrow(new WeakPassword('WEAK_PASSWORD'));
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'jane@example.com', 'emailVerified' => true])
        ->andReturn(userRecord());

    profilePage($admin, 'admin')
        ->fillForm([
            'email' => 'new@example.com',
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'secret',
        ])
        ->call('save')
        ->assertHasFormErrors(['password' => __('filament-firebase-auth::profile.messages.firebase_failed')]);

    expect($admin->refresh()->getAttribute('email'))->toBe('jane@example.com');
});

it('treats an email with other letter case as unchanged', function () {
    $admin = profileLinkedAdmin();
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('updateUser');

    profilePage($admin, 'admin')
        ->fillForm(['email' => 'Jane@Example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->refresh()->getAttribute('email'))->toBe('jane@example.com');
});

it('writes a changed name to firebase', function () {
    $user = linkedUser();
    $firebase = profileFirebase();
    $firebase->shouldReceive('updateUser')->once()->with('uid-1', ['displayName' => 'Jane Doe'])
        ->andReturn(userRecord(['displayName' => 'Jane Doe']));

    profilePage($user)
        ->fillForm(['name' => 'Jane Doe'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The sync of the next sign-in reads the Firebase name, which is now the new name.
    app(SyncsLocalUsers::class)->handle($user->refresh(), userRecord(['displayName' => 'Jane Doe']));

    expect($user->refresh()->getAttribute('name'))->toBe('Jane Doe');
});

it('checks the current password against the firebase email of the link', function () {
    $user = linkedUser(email: 'old@example.com');
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('signInWithEmailAndPassword')->with('old@example.com', Mockery::any());
    $firebase->shouldReceive('changeUserPassword')->once()->andReturn(userRecord());

    profilePage($user)
        ->fillForm([
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'secret',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('shows the password fields when firebase is down', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->andThrow(new ApiConnectionFailed('Firebase is down'));
    $firebase->shouldNotReceive('changeUserPassword');

    profilePage($user)
        ->assertFormFieldVisible('password')
        ->assertFormFieldEnabled('email')
        ->fillForm([
            'password' => 'new-secret-123',
            'passwordConfirmation' => 'new-secret-123',
            'currentPassword' => 'secret',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword' => __('filament-firebase-auth::profile.messages.firebase_unavailable')]);
});

it('limits the attempts of the account actions', function (string $action) {
    config()->set('filament-firebase-auth.global_logout', true);
    $user = linkedUser();
    $firebase = profileFirebase();
    $firebase->shouldNotReceive('revokeRefreshTokens');
    $firebase->shouldNotReceive('deleteUser');
    // A failed attempt keeps the modal open, so each attempt submits the mounted action again.
    $page = profilePage($user)->mountAction($action);

    foreach (range(1, 5) as $attempt) {
        $page->setActionData(['currentPassword' => 'wrong'])
            ->callMountedAction()
            ->assertHasActionErrors(['currentPassword']);
    }

    // The sixth attempt is refused before the password check, even with the right password.
    $page->setActionData(['currentPassword' => 'secret'])
        ->callMountedAction()
        ->assertNotified();

    expect(User::query()->count())->toBe(1)
        ->and($user->firebaseIdentity?->fresh()?->session_salt)->toBeNull()
        ->and(auth('app')->check())->toBeTrue();
})->with(['logoutOtherSessions', 'logoutEverywhere', 'deleteAccount']);

it('needs the current password to log out other sessions', function () {
    $user = linkedUser();
    profileFirebase();

    profilePage($user)
        ->callAction('logoutOtherSessions', data: ['currentPassword' => 'wrong'])
        ->assertHasActionErrors(['currentPassword' => __('validation.current_password')]);

    expect($user->firebaseIdentity?->fresh()?->session_salt)->toBeNull();
});

it('deletes the account of a google only user without a password', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord([
        'providerUserInfo' => [
            ['providerId' => 'google.com', 'rawId' => 'google-1', 'email' => 'jane@example.com'],
        ],
    ]));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    profilePage($user)
        ->callAction('deleteAccount')
        ->assertHasNoActionErrors()
        ->assertRedirect(Filament::getLoginUrl());

    expect(User::query()->count())->toBe(0);
});

it('keeps the session when firebase fails on log out everywhere', function () {
    config()->set('filament-firebase-auth.global_logout', true);
    $user = linkedUser();
    $firebase = profileFirebase();
    $firebase->shouldReceive('revokeRefreshTokens')->once()->andThrow(new ApiConnectionFailed('Firebase is down'));

    profilePage($user)
        ->callAction('logoutEverywhere', data: ['currentPassword' => 'secret'])
        ->assertNotified(__('filament-firebase-auth::profile.messages.firebase_failed'));

    expect($user->firebaseIdentity?->fresh()?->tokens_valid_after)->toBeNull()
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('renews the remember cookie when the request has one', function (bool $hasCookie) {
    $user = linkedUser();
    Filament::setCurrentPanel('app');
    actingAs($user, 'app');
    $guard = profileGuard();

    if ($hasCookie) {
        request()->cookies->set($guard->getRecallerName(), 'old');
    }

    app(RenewCurrentSession::class)->handle($user);

    expect(Cookie::hasQueued($guard->getRecallerName()))->toBe($hasCookie)
        ->and(session('password_hash_app'))->toBe(profileSessionHash($user));
})->with(['with a remember cookie' => true, 'without a remember cookie' => false]);
