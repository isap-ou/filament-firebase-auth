<?php

use Filament\Commands\MakeUserCommand;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Commands\MakeFirebaseUserCommand;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\LegacyUser;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\artisan;

/**
 * The options of a non-interactive run on the closed fixture panel.
 *
 * @return array<string, string>
 */
function makeUserOptions(string $panel = 'admin'): array
{
    return [
        '--panel' => $panel,
        '--name' => 'Ada',
        '--email' => 'ada@example.com',
        '--password' => 'secret-password',
    ];
}

/**
 * Binds a `CreatesLocalUsers` that always fails.
 */
function makeUserFailingLocalStep(): void
{
    app()->bind(CreatesLocalUsers::class, fn (): CreatesLocalUsers => new class implements CreatesLocalUsers
    {
        public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): never
        {
            throw new RuntimeException('The local step failed.');
        }
    });
}

/**
 * Binds a `CreatesLocalUsers` mock that fails the test when the command calls it.
 */
function makeUserForbiddenLocalStep(): void
{
    $createsLocalUsers = Mockery::mock(CreatesLocalUsers::class);
    $createsLocalUsers->shouldNotReceive('handle');

    app()->instance(CreatesLocalUsers::class, $createsLocalUsers);
}

it('resolves the command to the plugin class', fn () => expect(Artisan::all()['make:filament-user'])->toBeInstanceOf(MakeFirebaseUserCommand::class));

it('resolves the aliases to the plugin class', function () {
    $commands = Artisan::all();

    expect($commands['filament:make-user'])->toBeInstanceOf(MakeFirebaseUserCommand::class)
        ->and($commands['filament:user'])->toBeInstanceOf(MakeFirebaseUserCommand::class);
});

it('resolves the command to the plugin class when filament registers its command last', function () {
    // The callback runs after the callbacks of all providers, as if Filament booted after the plugin.
    ConsoleApplication::starting(function (ConsoleApplication $artisan): void {
        $artisan->resolveCommands([MakeUserCommand::class]);
    });

    $artisan = (new ConsoleApplication(app(), app('events'), app()->version()))->setContainerCommandLoader();

    expect($artisan->get('make:filament-user'))->toBeInstanceOf(MakeFirebaseUserCommand::class)
        ->and($artisan->get('filament:user'))->toBeInstanceOf(MakeFirebaseUserCommand::class)
        ->and($artisan->all()['make:filament-user'])->toBeInstanceOf(MakeFirebaseUserCommand::class);
});

it('creates the firebase account, the record and the link', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->with('ada@example.com')->andThrow(new UserNotFound('No user'));
    $firebase->shouldReceive('createUser')->once()->with([
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'emailVerified' => true,
        'displayName' => 'Ada',
    ])->andReturn(userRecord(['localId' => 'uid-9', 'email' => 'ada@example.com', 'displayName' => 'Ada']));
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())
        ->expectsOutputToContain('ada@example.com may now log in')
        ->assertSuccessful();

    $admin = Admin::query()->where('email', 'ada@example.com')->sole();

    expect($admin->getAttribute('name'))->toBe('Ada')
        ->and(DB::table('admins')->where('id', $admin->getKey())->value('password'))->toBeNull()
        ->and($admin->firebaseIdentity?->firebase_uid)->toBe('uid-9')
        ->and($admin->firebaseIdentity?->email_verified_at)->not->toBeNull();
});

it('links an existing firebase account without changing its password', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->with('ada@example.com')
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com', 'displayName' => 'Ada Lovelace']));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('changeUserPassword');
    $firebase->shouldNotReceive('updateUser');
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())
        ->expectsOutputToContain(__('filament-firebase-auth::command.existing_account', ['email' => 'ada@example.com']))
        ->assertSuccessful();

    $admin = Admin::query()->where('email', 'ada@example.com')->sole();

    expect($admin->firebaseIdentity?->firebase_uid)->toBe('uid-7')
        ->and(DB::table('admins')->where('id', $admin->getKey())->value('password'))->toBeNull();
});

it('refuses an existing firebase account with an unverified email', function () {
    makeUserForbiddenLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->with('ada@example.com')
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com', 'emailVerified' => false]));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('updateUser');
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())
        ->expectsOutputToContain(__('filament-firebase-auth::command.unverified_account', ['email' => 'ada@example.com']))
        ->doesntExpectOutputToContain('may now log in')
        ->assertFailed();

    expect(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('links an existing firebase account with a verified email', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->with('ada@example.com')
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com', 'emailVerified' => true]));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())->assertSuccessful();

    expect(Admin::query()->where('email', 'ada@example.com')->sole()->firebaseIdentity?->firebase_uid)->toBe('uid-7');
});

it('deletes only an account it created when the local step fails', function () {
    makeUserFailingLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->andThrow(new UserNotFound('No user'));
    $firebase->shouldReceive('createUser')->once()->andReturn(userRecord(['localId' => 'uid-9', 'email' => 'ada@example.com']));
    $firebase->shouldReceive('deleteUser')->once()->with('uid-9');

    expect(fn () => artisan('make:filament-user', makeUserOptions())->run())
        ->toThrow(RuntimeException::class, 'The local step failed.');

    expect(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('never deletes an existing account when the local step fails', function () {
    makeUserFailingLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com']));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('deleteUser');

    expect(fn () => artisan('make:filament-user', makeUserOptions())->run())
        ->toThrow(RuntimeException::class, 'The local step failed.');
});

it('refuses a disabled firebase account', function () {
    makeUserForbiddenLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com', 'disabled' => true]));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())
        ->expectsOutputToContain(__('filament-firebase-auth::command.disabled_account', ['email' => 'ada@example.com']))
        ->doesntExpectOutputToContain(__('filament-firebase-auth::command.existing_account', ['email' => 'ada@example.com']))
        ->assertFailed();

    expect(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('refuses a firebase account that another record of the model already has', function () {
    $other = Admin::query()->create(['name' => 'Old', 'email' => 'old@example.com']);
    $other->firebaseIdentity()->create(['firebase_uid' => 'uid-7', 'synced_at' => now()]);
    makeUserForbiddenLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com']));
    $firebase->shouldNotReceive('createUser');
    $firebase->shouldNotReceive('deleteUser');

    artisan('make:filament-user', makeUserOptions())
        ->expectsOutputToContain(__('filament-firebase-auth::command.linked_account', ['email' => 'ada@example.com']))
        ->doesntExpectOutputToContain(__('filament-firebase-auth::command.existing_account', ['email' => 'ada@example.com']))
        ->assertFailed();

    expect(Admin::query()->pluck('email')->all())->toBe(['old@example.com'])
        ->and(FirebaseIdentity::query()->count())->toBe(1);
});

it('rolls back the record when the link fails', function () {
    $link = Mockery::mock(LinkFirebaseIdentity::class);
    $link->shouldReceive('handle')->once()->andThrow(new LogicException('The link failed.'));
    app()->instance(LinkFirebaseIdentity::class, $link);
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com']));
    $firebase->shouldNotReceive('deleteUser');

    expect(fn () => artisan('make:filament-user', makeUserOptions())->run())
        ->toThrow(LogicException::class, 'The link failed.');

    expect(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('keeps the first error when the cleanup fails', function () {
    $log = Log::spy();
    makeUserFailingLocalStep();
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->andThrow(new UserNotFound('No user'));
    $firebase->shouldReceive('createUser')->once()->andReturn(userRecord(['localId' => 'uid-9', 'email' => 'ada@example.com']));
    $firebase->shouldReceive('deleteUser')->once()->with('uid-9')->andThrow(new RuntimeException('secret transport detail'));
    $output = new BufferedOutput;

    expect(fn () => Artisan::call('make:filament-user', makeUserOptions(), $output))
        ->toThrow(RuntimeException::class, 'The local step failed.');

    expect($output->fetch())->toContain(__('filament-firebase-auth::command.cleanup_failed', ['uid' => 'uid-9']));
    $log->shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class, 'uid' => 'uid-9']
            && ! str_contains($message . json_encode($context), 'secret transport detail'),
    );
});

it('falls back to filament for non firebase panels', function () {
    config()->set('auth.providers.legacy_users', ['driver' => 'eloquent', 'model' => LegacyUser::class]);
    config()->set('auth.guards.legacy', ['driver' => 'session', 'provider' => 'legacy_users']);
    // The registry is already resolved, so `Filament::registerPanel()` would wait for the next resolve.
    app(PanelRegistry::class)->register(Panel::make()->id('legacy')->authGuard('legacy'));
    // A mock without expectations fails on any Firebase call.
    mockFirebase();

    artisan('make:filament-user', makeUserOptions('legacy'))->assertSuccessful();

    $user = LegacyUser::query()->where('email', 'ada@example.com')->sole();

    expect($user->getAttribute('name'))->toBe('Ada')
        ->and(Hash::check('secret-password', $user->getAttribute('password')))->toBeTrue()
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});
