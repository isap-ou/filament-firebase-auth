<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\Signature;
use Lcobucci\JWT\UnencryptedToken;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withSession;

/**
 * Builds a verified Firebase ID token with the given `sub` claim.
 */
function socialSignInToken(string $uid = 'uid-1'): UnencryptedToken
{
    return new Plain(
        new DataSet(['alg' => 'RS256'], ''),
        new DataSet(['sub' => $uid], ''),
        new Signature('signature', 'signature'),
    );
}

/**
 * Returns the plugin of a fixture panel.
 */
function socialSignInPlugin(string $panel): FilamentFirebaseAuthPlugin
{
    $plugin = Filament::getPanel($panel)->getPlugin('filament-firebase-auth');

    if (! $plugin instanceof FilamentFirebaseAuthPlugin) {
        throw new RuntimeException("The panel [{$panel}] has no plugin.");
    }

    return $plugin;
}

/**
 * A fixture user that cannot access any panel.
 */
class SocialSignInDeniedUser extends User
{
    public function canAccessPanel(Panel $panel): bool
    {
        return false;
    }
}

// The panel middleware encrypts cookies. The shared test case sets no application key.
beforeEach(fn () => config()->set('app.key', 'base64:' . base64_encode(random_bytes(32))));

it('shows one button per provider on the native login page', function () {
    get('/app/login')
        ->assertOk()
        ->assertSee('google.com')
        ->assertSee(__('filament-firebase-auth::social.continue_with', ['provider' => 'Google']))
        ->assertDontSee('github.com');
});

it('shows the buttons in the order of the providers list', function () {
    socialSignInPlugin('app')->providers([SocialProvider::GitHub, SocialProvider::Google]);

    get('/app/login')
        ->assertOk()
        ->assertSeeInOrder([
            __('filament-firebase-auth::social.continue_with', ['provider' => 'GitHub']),
            __('filament-firebase-auth::social.continue_with', ['provider' => 'Google']),
        ]);
});

it('passes scopes and custom parameters to the button config', function () {
    socialSignInPlugin('app')
        ->providers([SocialProvider::Microsoft])
        ->providerScopes(SocialProvider::Microsoft, ['mail.read'])
        ->providerParameters(SocialProvider::Microsoft, ['tenant' => 'x']);

    get('/app/login')
        ->assertOk()
        ->assertSee('microsoft.com')
        ->assertSee('OAuthProvider')
        ->assertSee('mail.read')
        ->assertSee('tenant');
});

it('labels every provider', function () {
    $sdkClasses = ['GoogleAuthProvider', 'FacebookAuthProvider', 'GithubAuthProvider', 'TwitterAuthProvider', 'OAuthProvider'];

    foreach (SocialProvider::cases() as $provider) {
        $key = 'filament-firebase-auth::social.providers.' . strtolower($provider->name);

        expect(filled($provider->getLabel()) && $provider->getLabel() !== $key)->toBeTrue()
            ->and($provider->getIconPath())->toBeFile()
            ->and(file_get_contents($provider->getIconPath()))->toStartWith('<svg')
            ->and($provider->getSdkClass())->toBeIn($sdkClasses);
    }
});

it('shows the buttons on the registration page', function () {
    get('/app/register')->assertOk()->assertSee('google.com');
});

it('shows no buttons without providers', function () {
    get('/admin/login')
        ->assertOk()
        ->assertDontSee('google.com')
        ->assertDontSee('firebase/sign-in');
});

it('puts the sign-in route inside the panel middleware', function () {
    $route = Route::getRoutes()->getByName('filament.app.firebase.sign-in');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->uri())->toBe('app/firebase/sign-in')
        ->and($route->gatherMiddleware())->toContain(StartSession::class, VerifyCsrfToken::class, 'throttle:5,1');
});

it('signs in with a verified id token', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->with('token-1', true)->andReturn(socialSignInToken('uid-1'));
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertOk()
        ->assertJsonStructure(['redirect'])
        ->assertJson(['redirect' => Filament::getPanel('app')->getUrl()]);

    $user = User::query()->where('email', 'jane@example.com')->sole();

    expect(auth('app')->check())->toBeTrue()
        ->and(auth('app')->id())->toBe($user->getKey())
        ->and(FirebaseIdentity::query()->where('firebase_uid', 'uid-1')->count())->toBe(1);
});

it('redirects to the intended url', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->andReturn(socialSignInToken('uid-1'));
    $firebase->shouldReceive('getUser')->andReturn(userRecord());
    linkedUser();

    withSession(['url.intended' => 'http://localhost/app/profile'])
        ->postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertOk()
        ->assertJson(['redirect' => 'http://localhost/app/profile']);
});

it('rejects an invalid token with the generic message', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->andThrow(new FailedToVerifyToken('The token is invalid.'));
    $firebase->shouldNotReceive('getUser');

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('app')->check())->toBeFalse();
});

it('rejects a revoked token', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->with('token-1', true)->andThrow(new RevokedIdToken(socialSignInToken('uid-1')));

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('app')->check())->toBeFalse();
});

it('rejects a disabled firebase user', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->andReturn(socialSignInToken('uid-1'));
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['disabled' => true]));
    linkedUser();

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('app')->check())->toBeFalse();
});

it('rejects a user who cannot access the panel', function () {
    config()->set('auth.providers.firebase_users.model', SocialSignInDeniedUser::class);

    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->andReturn(socialSignInToken('uid-1'));
    $firebase->shouldReceive('getUser')->once()->andReturn(userRecord());

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('app')->check())->toBeFalse();
});

it('blocks and cleans up a new account on a closed panel in new_only mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'new_only');

    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->with('token-1', true)->andReturn(socialSignInToken('uid-new'));
    $firebase->shouldReceive('getUser')->once()->with('uid-new')->andReturn(userRecord([
        'localId' => 'uid-new',
        'email' => 'new@example.com',
    ]));
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new');

    postJson('/admin/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('admin')->check())->toBeFalse()
        ->and(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('blocks a new account on a closed panel without deleting it by default', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->andReturn(socialSignInToken('uid-new'));
    $firebase->shouldReceive('getUser')->once()->andReturn(userRecord(['localId' => 'uid-new', 'email' => 'new@example.com']));
    $firebase->shouldNotReceive('deleteUser');

    postJson('/admin/firebase/sign-in', ['credential' => 'token-1'])->assertStatus(422);

    expect(Admin::query()->count())->toBe(0);
});

it('never reads the credential from the query string', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('verifyIdToken');

    postJson('/app/firebase/sign-in?credential=token-1', [])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);
});

it('rate limits token attempts', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->times(5)->andThrow(new FailedToVerifyToken('The token is invalid.'));

    foreach (range(1, 5) as $attempt) {
        postJson('/app/firebase/sign-in', ['credential' => 'token-1'])->assertStatus(422);
    }

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])->assertStatus(429);
});

it('stores the provider settings', function () {
    $plugin = FilamentFirebaseAuthPlugin::make()
        ->providers([SocialProvider::Microsoft, SocialProvider::Google, SocialProvider::Microsoft])
        ->providerScopes(SocialProvider::Microsoft, ['mail.read', 'calendars.read'])
        ->providerParameters(SocialProvider::Microsoft, ['tenant' => 'x']);

    expect($plugin->getProviders())->toBe([SocialProvider::Microsoft, SocialProvider::Google])
        ->and($plugin->getProviderScopes(SocialProvider::Microsoft))->toBe(['mail.read', 'calendars.read'])
        ->and($plugin->getProviderParameters(SocialProvider::Microsoft))->toBe(['tenant' => 'x'])
        ->and($plugin->getProviderScopes(SocialProvider::Google))->toBe([])
        ->and($plugin->getProviderParameters(SocialProvider::Google))->toBe([]);
});

it('rejects a provider id string', function () {
    // @phpstan-ignore argument.type (The test passes a provider ID string on purpose.)
    FilamentFirebaseAuthPlugin::make()->providers(['google.com']);
})->throws(TypeError::class);

it('rejects an unknown social sign-in method', function () {
    config()->set('filament-firebase-auth.social_sign_in', 'socialite');

    app(SocialSignInMethod::class);
})->throws(InvalidArgumentException::class, 'The social sign-in method [socialite] is not supported.');

it('fails closed when firebase is unreachable', function () {
    $log = Log::spy();

    $firebase = mockFirebase();
    $firebase->shouldReceive('verifyIdToken')->once()->andReturn(socialSignInToken('uid-1'));
    $firebase->shouldReceive('getUser')->once()->andThrow(new ApiConnectionFailed('Firebase is down'));

    postJson('/app/firebase/sign-in', ['credential' => 'token-1'])
        ->assertStatus(422)
        ->assertExactJson(['message' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect(auth('app')->check())->toBeFalse();

    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['exception' => ApiConnectionFailed::class],
    );
});
