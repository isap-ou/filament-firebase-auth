<?php

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Isapp\FilamentFirebaseAuth\Auth\FirebaseUserProvider;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Auth\SignIn\FailedToSignIn;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\get;
use function Pest\Laravel\withoutExceptionHandling;
use function Pest\Livewire\livewire;

/**
 * Opens Filament's own login page of a fixture panel and submits the email and password.
 */
function signIn(string $panel, string $password = 'secret', string $email = 'jane@example.com'): Testable
{
    Filament::setCurrentPanel($panel);

    return livewire(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate');
}

/**
 * Returns the user provider of a fixture guard.
 */
function firebaseProvider(string $guard): FirebaseUserProvider
{
    $sessionGuard = auth()->guard($guard);
    $provider = $sessionGuard instanceof SessionGuard ? $sessionGuard->getProvider() : null;

    if (! $provider instanceof FirebaseUserProvider) {
        throw new RuntimeException("The guard [{$guard}] does not use the firebase user provider.");
    }

    return $provider;
}

function failedMessage(): string
{
    return __('filament-panels::auth/pages/login.messages.failed');
}

it('registers the firebase driver for the fixture providers', function () {
    expect(firebaseProvider('app')->getModel())->toBe(User::class)
        ->and(firebaseProvider('admin')->getModel())->toBe(Admin::class);
});

it('signs in a linked user', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->with('jane@example.com')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->with('jane@example.com', 'secret')
        ->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord(['displayName' => 'Jane Doe']));

    signIn('app')->assertHasNoFormErrors()->assertRedirect();

    expect(auth('app')->check())->toBeTrue()
        ->and(auth('app')->id())->toBe($user->getKey())
        ->and($user->refresh()->getAttribute('name'))->toBe('Jane Doe');
});

it('creates the record on an open panel after the password check', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['displayName' => 'Jane']));
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord(['displayName' => 'Jane']));

    expect(User::query()->count())->toBe(0);

    signIn('app')->assertHasNoFormErrors()->assertRedirect();

    $user = User::query()->sole();

    expect($user->getAttribute('email'))->toBe('jane@example.com')
        ->and(FirebaseIdentity::query()->forFirebaseUid('uid-1', $user->getMorphClass())->value('authenticatable_id'))
        ->toBe($user->getKey())
        ->and(auth('app')->id())->toBe($user->getKey());
});

it('creates nothing when the password is wrong', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));
    $firebase->shouldNotReceive('getUser');

    signIn('app', 'wrong')->assertHasFormErrors(['email' => failedMessage()]);

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('app')->check())->toBeFalse();
});

it('fails like a wrong password for an unknown email', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andThrow(new UserNotFound('No user'));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    signIn('app')->assertHasFormErrors(['email' => failedMessage()]);

    expect(User::query()->count())->toBe(0);
});

it('blocks on a closed panel with the generic message', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldNotReceive('signInWithEmailAndPassword');
    $firebase->shouldNotReceive('deleteUser');

    signIn('admin')->assertHasFormErrors(['email' => failedMessage()]);

    expect(Admin::query()->count())->toBe(0)
        ->and(auth('admin')->check())->toBeFalse();
});

it('links a verified record by email on a closed panel after the password check', function () {
    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->andReturn(userRecord());

    signIn('admin')->assertHasNoFormErrors()->assertRedirect();

    expect(auth('admin')->id())->toBe($admin->getKey())
        ->and($admin->refresh()->firebaseIdentity?->firebase_uid)->toBe('uid-1');
});

it('never deletes when the password is wrong', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));
    $firebase->shouldNotReceive('deleteUser');

    signIn('admin', 'wrong')->assertHasFormErrors(['email' => failedMessage()]);
});

it('deletes after a proven password on a closed panel in orphaned mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->with('uid-1')->andReturn(userRecord());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-1');

    signIn('admin')->assertHasFormErrors(['email' => failedMessage()]);

    expect(Admin::query()->count())->toBe(0)
        ->and(auth('admin')->check())->toBeFalse();
});

it('never deletes an account that a closed panel blocks for another reason', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    // An unverified Firebase email never links the record with the same email.
    Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');
    $firebase->shouldNotReceive('deleteUser');

    signIn('admin')->assertHasFormErrors(['email' => failedMessage()]);

    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('rejects a disabled firebase account', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord(['disabled' => true]));
    $firebase->shouldNotReceive('signInWithEmailAndPassword');

    signIn('app')->assertHasFormErrors(['email' => failedMessage()]);

    expect(auth('app')->check())->toBeFalse();

    // Firebase rejects the password of a disabled account. The provider also refuses a disabled user record.
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->andReturn(userRecord(['disabled' => true]));

    expect(firebaseProvider('app')->validateCredentials($user, ['email' => 'jane@example.com', 'password' => 'secret']))
        ->toBeFalse();
});

it('calls firebase once per request', function () {
    linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());

    signIn('app')->assertHasNoFormErrors()->assertRedirect();

    expect(User::query()->count())->toBe(1)
        ->and(FirebaseIdentity::query()->count())->toBe(1);
});

it('refuses the credentials of another firebase user', function () {
    $user = linkedUser();
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-2'));
    $firebase->shouldReceive('getUser')->with('uid-2')
        ->andReturn(userRecord(['localId' => 'uid-2', 'email' => 'other@example.com']));

    Filament::setCurrentPanel('app');

    expect(firebaseProvider('app')->validateCredentials($user, ['email' => 'other@example.com', 'password' => 'secret']))
        ->toBeFalse()
        ->and(User::query()->count())->toBe(1);
});

it('fails closed when firebase is unreachable', function () {
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andThrow(new ApiConnectionFailed('Firebase is down'));

    signIn('app', 'p4ss-W0rd!')->assertHasFormErrors(['email' => failedMessage()]);

    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['exception' => ApiConnectionFailed::class]
            && ! str_contains($message . json_encode($context), 'p4ss-W0rd!')
            && ! str_contains($message . json_encode($context), 'jane@example.com'),
    );
    expect(auth('app')->check())->toBeFalse();
});

it('fails closed when the password check cannot reach firebase', function () {
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')
        ->andThrow(FailedToSignIn::fromPrevious(new RuntimeException('Connection timed out for p4ss-W0rd!')));

    signIn('app', 'p4ss-W0rd!')->assertHasFormErrors(['email' => failedMessage()]);

    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['exception' => FailedToSignIn::class]
            && ! str_contains($message, 'p4ss-W0rd!'),
    );
    expect(User::query()->count())->toBe(0);
});

it('logs the firebase error code of a configuration error', function () {
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(400, 'OPERATION_NOT_ALLOWED : Password sign-in is disabled for this project.'));

    signIn('app')->assertHasFormErrors(['email' => failedMessage()]);

    $log->shouldHaveReceived('warning')->once()
        ->with('Firebase refused the password check because of its configuration.', ['code' => 'OPERATION_NOT_ALLOWED']);
});

it('logs nothing for wrong credentials', function () {
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));

    signIn('app', 'wrong')->assertHasFormErrors(['email' => failedMessage()]);

    $log->shouldNotHaveReceived('warning');
    $log->shouldNotHaveReceived('error');
});

it('fails with the generic message when a soft deleted record holds the email', function () {
    // The closed fixture panel opens for this test, so the soft deleted record resolves to a new record.
    /** @var FilamentFirebaseAuthPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin('filament-firebase-auth');
    $plugin->createOnSignIn(true);
    Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com'])->delete();
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->andReturn(userRecord());

    signIn('admin')->assertHasFormErrors(['email' => failedMessage()]);

    expect(Admin::withTrashed()->count())->toBe(1)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('admin')->check())->toBeFalse();
    $log->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'jane@example.com'),
    );
});

it('fails with the generic message when the record cannot be created', function () {
    app()->bind(CreatesLocalUsers::class, fn (): CreatesLocalUsers => new class implements CreatesLocalUsers
    {
        public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable
        {
            throw new LogicException('The "users" table has a NOT NULL "password" column.');
        }
    });
    $log = Log::spy();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->andReturn(userRecord());
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('getUser')->andReturn(userRecord());

    signIn('app')->assertHasFormErrors(['email' => failedMessage()]);

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
    $log->shouldHaveReceived('error')->once()
        ->with('The "users" table has a NOT NULL "password" column.', ['exception' => LogicException::class]);
});

it('rejects the native registration page on a firebase panel request', function () {
    Filament::getPanel('app')->registration();

    withoutExceptionHandling();

    expect(fn () => get('/app/login'))
        ->toThrow(LogicException::class, '->registration(\Isapp\FilamentFirebaseAuth\Pages\Register::class)');
});
