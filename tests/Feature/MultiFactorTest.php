<?php

use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\Route;
use Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use Lcobucci\JWT\UnencryptedToken;
use Livewire\Features\SupportTesting\Testable;
use Mockery\MockInterface;
use PragmaRX\Google2FAQRCode\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Livewire\livewire;

/**
 * A Firebase user that signs in with Google only. It has no `password` provider.
 */
function multiFactorGoogleRecord(): UserRecord
{
    return userRecord([
        'providerUserInfo' => [
            ['providerId' => 'google.com', 'rawId' => 'google-1', 'email' => 'jane@example.com'],
        ],
    ]);
}

/**
 * Mocks Firebase for a linked user: the record lookup and a password check of `secret`.
 */
function multiFactorFirebase(?UserRecord $record = null): MockInterface
{
    $record ??= userRecord();

    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn($record);
    $firebase->shouldReceive('getUserByEmail')->with('jane@example.com')->andReturn($record);
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'secret')->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('signInWithEmailAndPassword')->with('jane@example.com', 'wrong')
        ->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));

    return $firebase;
}

/**
 * Saves an app authentication secret for the user and returns it.
 */
function multiFactorEnable(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey(16);

    $user->saveAppAuthenticationSecret($secret);

    return $secret;
}

function multiFactorCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Builds a verified Firebase ID token of a Google sign-in of right now.
 */
function multiFactorGoogleToken(): UnencryptedToken
{
    return new Plain(
        new DataSet(['alg' => 'RS256'], ''),
        new DataSet([
            'sub' => 'uid-1',
            'auth_time' => now()->getTimestamp(),
            'firebase' => ['identities' => [], 'sign_in_provider' => 'google.com'],
        ], ''),
        new Signature('signature', 'signature'),
    );
}

/**
 * Makes multi-factor authentication required on the app panel and loads the panel routes again,
 * because Filament reads the page middleware when it registers the routes.
 */
function multiFactorRequire(): void
{
    Filament::getPanel('app')->requiresMultiFactorAuthentication();

    require dirname((string) (new ReflectionClass(FilamentServiceProvider::class))->getFileName(), 2) . '/routes/web.php';

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/**
 * Opens the profile page of the app panel as the given user.
 */
function multiFactorProfile(User $user): Testable
{
    actingAs($user, 'app');
    Filament::setCurrentPanel('app');

    return livewire(EditProfile::class);
}

/**
 * Reads the secret that the mounted set-up action generated.
 */
function multiFactorMountedSecret(Testable $profile): string
{
    $mountedActions = $profile->get('mountedActions');
    $secret = is_array($mountedActions) ? decrypt($mountedActions[0]['arguments']['encrypted'])['secret'] : null;

    if (! is_string($secret)) {
        throw new RuntimeException('The set-up action is not mounted.');
    }

    return $secret;
}

/**
 * Returns the app authentication provider of the app panel.
 */
function multiFactorProvider(): AppAuthentication
{
    $provider = Filament::getPanel('app')->getMultiFactorAuthenticationProviders()['app'] ?? null;

    if (! $provider instanceof AppAuthentication) {
        throw new RuntimeException('The app panel has no plugin app authentication.');
    }

    return $provider;
}

function multiFactorAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('app', 'content');
}

// The panel middleware encrypts cookies, and the secret column is encrypted. The shared test case sets no key.
beforeEach(fn () => config()->set('app.key', 'base64:' . base64_encode(random_bytes(32))));

it('asks the app code after a valid firebase password', function () {
    $user = linkedUser();
    $secret = multiFactorEnable($user);
    multiFactorFirebase();
    Filament::setCurrentPanel('app');

    $login = livewire(Login::class)
        ->fillForm(['email' => 'jane@example.com', 'password' => 'secret'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();

    expect(auth('app')->check())->toBeFalse()
        ->and($login->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    $login->set('data.multiFactor.app.code', multiFactorCode($secret))
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(auth('app')->id())->toBe($user->getKey());
});

it('sets up app authentication with the firebase password', function () {
    $user = linkedUser();
    multiFactorFirebase();

    $profile = multiFactorProfile($user)->mountAction(multiFactorAction('setUpAppAuthentication'));
    $secret = multiFactorMountedSecret($profile);

    $profile->fillForm(['code' => multiFactorCode($secret), 'password' => 'secret'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($user->refresh()->getAppAuthenticationSecret())->toBe($secret);
});

it('refuses app authentication setup with a wrong firebase password', function () {
    $user = linkedUser();
    multiFactorFirebase();

    $profile = multiFactorProfile($user)->mountAction(multiFactorAction('setUpAppAuthentication'));
    $secret = multiFactorMountedSecret($profile);

    $profile->fillForm(['code' => multiFactorCode($secret), 'password' => 'wrong'])
        ->callMountedAction()
        ->assertHasFormErrors(['password' => __('validation.current_password')]);

    expect($user->refresh()->getAppAuthenticationSecret())->toBeNull();
});

it('refuses app authentication setup when firebase is unreachable', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->andThrow(new ApiConnectionFailed('Firebase is down'));

    $profile = multiFactorProfile($user)->mountAction(multiFactorAction('setUpAppAuthentication'));
    $secret = multiFactorMountedSecret($profile);

    $profile->fillForm(['code' => multiFactorCode($secret), 'password' => 'secret'])
        ->callMountedAction()
        ->assertHasFormErrors(['password' => __('filament-firebase-auth::multi-factor.messages.firebase_unavailable')]);

    expect($user->refresh()->getAppAuthenticationSecret())->toBeNull();
});

it('disables app authentication with the firebase password only', function () {
    $user = linkedUser();
    $secret = multiFactorEnable($user);
    multiFactorFirebase();

    multiFactorProfile($user)
        ->callAction(multiFactorAction('disableAppAuthentication'), ['code' => multiFactorCode($secret), 'password' => 'wrong'])
        ->assertHasFormErrors(['password' => __('validation.current_password')]);

    expect($user->refresh()->getAppAuthenticationSecret())->toBe($secret);

    multiFactorProfile($user)
        ->callAction(multiFactorAction('disableAppAuthentication'), ['code' => multiFactorCode($secret), 'password' => 'secret'])
        ->assertHasNoFormErrors();

    expect($user->refresh()->getAppAuthenticationSecret())->toBeNull();
});

it('regenerates recovery codes with the firebase password', function () {
    multiFactorProvider()->recoverable();

    $user = linkedUser();
    multiFactorEnable($user);
    $user->saveAppAuthenticationRecoveryCodes(['old-code']);
    multiFactorFirebase();

    multiFactorProfile($user)
        ->callAction(multiFactorAction('regenerateAppAuthenticationRecoveryCodes'), ['password' => 'secret'])
        ->assertHasNoFormErrors();

    expect($user->refresh()->getAppAuthenticationRecoveryCodes())->not->toBe(['old-code']);
});

it('hides app authentication management for users without a password', function () {
    $user = linkedUser();
    multiFactorFirebase(multiFactorGoogleRecord());

    actingAs($user, 'app')
        ->get('/app/profile')
        ->assertOk()
        ->assertDontSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));

    expect(app(AppAuthentication::class)->getManagementSchemaComponents())->toBe([]);
});

it('shows app authentication management for users with a password', function () {
    $user = linkedUser();
    multiFactorFirebase();

    actingAs($user, 'app')
        ->get('/app/profile')
        ->assertOk()
        ->assertSee(__('filament-panels::auth/multi-factor/app/actions/set-up.label'));
});

it('lets a user without a password through required mfa', function () {
    multiFactorRequire();
    $user = linkedUser();
    multiFactorFirebase(multiFactorGoogleRecord());

    actingAs($user, 'app')->get('/app')->assertOk();
});

it('still sends a password user without mfa to the set-up page', function () {
    multiFactorRequire();
    $user = linkedUser();
    multiFactorFirebase();

    actingAs($user, 'app')
        ->get('/app')
        ->assertRedirect(Filament::getPanel('app')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('lets a password user with mfa through required mfa without a firebase call', function () {
    multiFactorRequire();
    $user = linkedUser();
    multiFactorEnable($user);
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');
    config()->set('filament-firebase-auth.revalidate_every', 0);

    actingAs($user, 'app')->get('/app')->assertOk();
});

it('sends a user to the set-up page when firebase cannot tell the providers', function () {
    multiFactorRequire();
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->andThrow(new ApiConnectionFailed('Firebase is down'));
    config()->set('filament-firebase-auth.revalidate_every', 0);

    actingAs($user, 'app')
        ->get('/app')
        ->assertRedirect(Filament::getPanel('app')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('signs in with a social provider without the filament challenge', function () {
    $user = linkedUser();
    multiFactorEnable($user);
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->with('token-1', true)->andReturn(multiFactorGoogleToken());
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(multiFactorGoogleRecord());

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertOk()
        ->assertJson(['redirect' => Filament::getPanel('app')->getUrl()]);

    expect(auth('app')->id())->toBe($user->getKey());
});
