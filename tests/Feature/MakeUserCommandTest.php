<?php

use Filament\Commands\MakeUserCommand;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Isapp\FilamentFirebaseAuth\Commands\MakeFirebaseUserCommand;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\LegacyUser;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\UserNotFound;

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

it('rolls back the record when the link fails', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()
        ->andReturn(userRecord(['localId' => 'uid-7', 'email' => 'ada@example.com', 'disabled' => true]));
    $firebase->shouldNotReceive('deleteUser');

    expect(fn () => artisan('make:filament-user', makeUserOptions())->run())
        ->toThrow(LogicException::class, 'The Firebase user is disabled.');

    expect(Admin::query()->count())->toBe(0)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
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
