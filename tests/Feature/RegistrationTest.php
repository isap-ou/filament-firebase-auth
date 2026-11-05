<?php

use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Pages\Register as FilamentRegister;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages\FailingAfterRegister;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages\TransactionalRegister;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

/**
 * Opens a registration page on the open fixture panel and submits the form.
 *
 * @param  class-string<Register>  $page
 */
function submitRegistration(string $email = 'new@example.com', string $password = 'Secret123!', string $page = Register::class): Testable
{
    Filament::setCurrentPanel('app');

    return livewire($page)
        ->fillForm([
            'name' => 'New User',
            'email' => $email,
            'password' => $password,
            'passwordConfirmation' => $password,
        ])
        ->call('register');
}

/**
 * The Firebase user that the mock returns for a new registration.
 */
function registeredFirebaseUser(): UserRecord
{
    return userRecord([
        'localId' => 'uid-new',
        'email' => 'new@example.com',
        'displayName' => 'New User',
        'emailVerified' => false,
        'providerUserInfo' => [
            ['providerId' => 'password', 'rawId' => 'new@example.com', 'email' => 'new@example.com'],
        ],
    ]);
}

/**
 * Binds a local user action that always fails.
 */
function failLocalUserCreation(): void
{
    app()->bind(CreatesLocalUsers::class, fn (): CreatesLocalUsers => new class implements CreatesLocalUsers
    {
        public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable
        {
            throw new RuntimeException('The local record failed.');
        }
    });
}

it('registers in firebase and locally', function () {
    Notification::fake();
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->with([
        'email' => 'new@example.com',
        'password' => 'Secret123!',
        'emailVerified' => false,
        'displayName' => 'New User',
    ])->andReturn(registeredFirebaseUser());

    submitRegistration()->assertHasNoFormErrors()->assertRedirect();

    $user = User::query()->where('email', 'new@example.com')->sole();
    $identity = $user->firebaseIdentity;

    expect(FirebaseIdentity::query()->count())->toBe(1)
        ->and($user->getAttribute('name'))->toBe('New User')
        ->and($identity?->firebase_uid)->toBe('uid-new')
        ->and($identity?->authenticatable_id)->toEqual($user->getKey())
        ->and($identity?->email_verified_at)->toBeNull()
        ->and(auth('app')->id())->toBe($user->getKey());

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('never stores the password locally', function () {
    Notification::fake();
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andReturn(registeredFirebaseUser());

    submitRegistration()->assertHasNoFormErrors();

    expect(DB::table('users')->where('email', 'new@example.com')->value('password'))->toBeNull();
});

it('keeps the native registration form', function () {
    Filament::setCurrentPanel('app');

    $fields = function (Testable $page): array {
        $instance = $page->instance();

        expect($instance)->toBeInstanceOf(FilamentRegister::class);

        /** @var FilamentRegister $instance */
        return array_keys($instance->form->getFlatFields(withHidden: true));
    };

    expect($fields(livewire(Register::class)))
        ->toBe(['name', 'email', 'password', 'passwordConfirmation'])
        ->toBe($fields(livewire(FilamentRegister::class)));
});

it('shows a firebase-only account as a local duplicate', function () {
    User::query()->create(['name' => 'Taken', 'email' => 'taken@example.com']);
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andThrow(new EmailExists('EMAIL_EXISTS'));
    $firebase->shouldNotReceive('deleteUser');

    // Filament's own unique rule rejects the local duplicate before any Firebase call.
    $localMessage = submitRegistration('taken@example.com')->errors()->first('data.email');

    expect($localMessage)->toBe('The email address has already been taken.');

    submitRegistration()->assertHasFormErrors(['email' => $localMessage]);

    expect(User::query()->count())->toBe(1)
        ->and(auth('app')->check())->toBeFalse();
});

it('deletes the firebase account once when the page transaction rolls back later', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andReturn(registeredFirebaseUser());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new');

    expect(fn (): Testable => submitRegistration(page: FailingAfterRegister::class))
        ->toThrow(RuntimeException::class, 'The afterRegister hook failed.');

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('app')->check())->toBeFalse();
});

it('deletes the firebase account once when the local step fails inside the page transaction', function () {
    failLocalUserCreation();
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andReturn(registeredFirebaseUser());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new');

    expect(fn (): Testable => submitRegistration(page: TransactionalRegister::class))
        ->toThrow(RuntimeException::class, 'The local record failed.');
});

it('deletes the firebase account when local creation fails', function () {
    failLocalUserCreation();
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andReturn(registeredFirebaseUser());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new');

    expect(fn (): Testable => submitRegistration())->toThrow(RuntimeException::class, 'The local record failed.');

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0)
        ->and(auth('app')->check())->toBeFalse();
});

it('rolls back the local record when the link fails', function () {
    $firebase = mockFirebase();
    // A disabled Firebase user cannot be linked, so the link step fails after the record is saved.
    $firebase->shouldReceive('createUser')->once()->andReturn(userRecord([
        'localId' => 'uid-new',
        'email' => 'new@example.com',
        'disabled' => true,
    ]));
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new');

    expect(fn (): Testable => submitRegistration())->toThrow(FirebaseIdentityNotLinkable::class);

    expect(User::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('keeps the local error when the firebase deletion fails', function () {
    $log = Log::spy();
    failLocalUserCreation();
    $firebase = mockFirebase();
    $firebase->shouldReceive('createUser')->once()->andReturn(registeredFirebaseUser());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-new')->andThrow(new ApiConnectionFailed('Firebase is down'));

    expect(fn (): Testable => submitRegistration())->toThrow(RuntimeException::class, 'The local record failed.');

    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['uid' => 'uid-new', 'exception' => ApiConnectionFailed::class],
    );
});
