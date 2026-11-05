<?php

use Isapp\FilamentFirebaseAuth\Actions\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\CreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\DetectPasswordProvider;
use Isapp\FilamentFirebaseAuth\Actions\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Actions\FindOrCreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Actions\SetFirebaseEmailVerified;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Auth\SignIn\FailedToSignIn;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\UserNotFound;

it('keeps one instance of the memoizing actions per request', function () {
    mockFirebase();
    $find = app(FindFirebaseUser::class);
    $check = app(CheckFirebasePassword::class);

    expect(app(FindFirebaseUser::class))->toBe($find)
        ->and(app(CheckFirebasePassword::class))->toBe($check);

    app()->forgetScopedInstances();

    expect(app(FindFirebaseUser::class))->not->toBe($find)
        ->and(app(CheckFirebasePassword::class))->not->toBe($check);
});

it('checks a password once per request', function () {
    mockFirebase()->shouldReceive('signInWithEmailAndPassword')->once()->andReturn(signInResult('uid-1'))
        ->getMock()->shouldReceive('getUser')->andReturn(userRecord());
    $check = app(CheckFirebasePassword::class);
    $check->handle('jane@example.com', 'secret');
    expect(app(CheckFirebasePassword::class)->handle('jane@example.com', 'secret')->uid)->toBe('uid-1');
});

it('checks a different password again', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->with('jane@example.com', 'secret')
        ->andReturn(signInResult('uid-1'));
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->with('jane@example.com', 'other')
        ->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());
    $check = app(CheckFirebasePassword::class);

    expect($check->handle('jane@example.com', 'secret'))->not->toBeNull()
        ->and($check->handle('jane@example.com', 'other'))->toBeNull();
});

it('returns null for a wrong password', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(400, 'INVALID_LOGIN_CREDENTIALS'));
    $firebase->shouldNotReceive('getUser');

    expect(app(CheckFirebasePassword::class)->handle('jane@example.com', 'wrong'))->toBeNull();
});

it('rethrows a transport failure', function () {
    mockFirebase()->shouldReceive('signInWithEmailAndPassword')->twice()
        ->andThrow(FailedToSignIn::fromPrevious(new RuntimeException('Connection timed out')));
    $check = app(CheckFirebasePassword::class);

    expect(fn () => $check->handle('jane@example.com', 'secret'))->toThrow(FailedToSignIn::class)
        ->and(fn () => $check->handle('jane@example.com', 'secret'))->toThrow(FailedToSignIn::class);
});

it('rethrows a firebase server error on sign-in', function () {
    mockFirebase()->shouldReceive('signInWithEmailAndPassword')->once()->andThrow(failedSignIn(503, 'UNAVAILABLE'));

    expect(fn () => app(CheckFirebasePassword::class)->handle('jane@example.com', 'secret'))
        ->toThrow(FailedToSignIn::class);
});

it('finds a firebase user once per request', function () {
    mockFirebase()->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());

    expect(app(FindFirebaseUser::class)->handle('uid-1')?->uid)->toBe('uid-1')
        ->and(app(FindFirebaseUser::class)->handle('uid-1')?->uid)->toBe('uid-1');
});

it('returns null for an unknown uid', function () {
    mockFirebase()->shouldReceive('getUser')->once()->andThrow(new UserNotFound('No user'));
    $find = app(FindFirebaseUser::class);

    expect($find->handle('uid-9'))->toBeNull()
        ->and($find->handle('uid-9'))->toBeNull();
});

it('lets other firebase errors through and does not remember them', function () {
    $calls = 0;
    mockFirebase()->shouldReceive('getUser')->twice()->andReturnUsing(function () use (&$calls) {
        if ($calls++ === 0) {
            throw new ApiConnectionFailed('Firebase is down');
        }

        return userRecord();
    });
    $find = app(FindFirebaseUser::class);

    expect(fn () => $find->handle('uid-1'))->toThrow(ApiConnectionFailed::class)
        ->and($find->handle('uid-1')?->uid)->toBe('uid-1');
});

it('finds a firebase user by email', function () {
    mockFirebase()->shouldReceive('getUserByEmail')->once()->with('jane@example.com')->andReturn(userRecord());

    expect(app(FindFirebaseUserByEmail::class)->handle('jane@example.com')?->uid)->toBe('uid-1');
});

it('returns null for an unknown email', function () {
    mockFirebase()->shouldReceive('getUserByEmail')->once()->andThrow(new UserNotFound('No user'));

    expect(app(FindFirebaseUserByEmail::class)->handle('nobody@example.com'))->toBeNull();
});

it('creates a firebase account with a name and verification', function () {
    mockFirebase()->shouldReceive('createUser')->once()->with([
        'email' => 'jane@example.com',
        'password' => 'secret',
        'emailVerified' => true,
        'displayName' => 'Jane',
    ])->andReturn(userRecord(['displayName' => 'Jane']));

    expect(app(CreateFirebaseUser::class)->handle('jane@example.com', 'secret', 'Jane', true)->displayName)->toBe('Jane');
});

it('creates a firebase account without a name', function () {
    mockFirebase()->shouldReceive('createUser')->once()->with([
        'email' => 'jane@example.com',
        'password' => 'secret',
        'emailVerified' => false,
    ])->andReturn(userRecord(['emailVerified' => false]));

    expect(app(CreateFirebaseUser::class)->handle('jane@example.com', 'secret', null, false)->emailVerified)->toBeFalse();
});

it('keeps the password of an existing account', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->andReturn(userRecord());
    $firebase->shouldNotReceive('changeUserPassword');
    $firebase->shouldNotReceive('createUser');

    [$record, $created] = app(FindOrCreateFirebaseUser::class)->handle('jane@example.com', 'secret', 'Jane', true);

    expect($record->uid)->toBe('uid-1')
        ->and($created)->toBeFalse();
});

it('creates a missing account', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUserByEmail')->once()->andThrow(new UserNotFound('No user'));
    $firebase->shouldReceive('createUser')->once()->andReturn(userRecord(['localId' => 'uid-2']));

    [$record, $created] = app(FindOrCreateFirebaseUser::class)->handle('jane@example.com', 'secret', 'Jane', true);

    expect($record->uid)->toBe('uid-2')
        ->and($created)->toBeTrue();
});

it('detects the password provider', function () {
    $detect = app(DetectPasswordProvider::class);

    expect($detect->handle(userRecord()))->toBeTrue()
        ->and($detect->handle(userRecord([
            'providerUserInfo' => [
                ['providerId' => 'google.com', 'rawId' => 'g-1', 'email' => 'jane@example.com'],
            ],
        ])))->toBeFalse();
});

it('links a local record to a firebase user', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    $identity = app(LinkFirebaseIdentity::class)->handle($user, userRecord(['validSince' => '1700000000']));

    expect($identity->exists)->toBeTrue()
        ->and($identity->firebase_uid)->toBe('uid-1')
        ->and($identity->email_verified_at)->not->toBeNull()
        ->and($identity->tokens_valid_after?->getTimestamp())->toBe(1700000000)
        ->and($identity->synced_at)->not->toBeNull()
        ->and($user->getFirebaseUid())->toBe('uid-1')
        ->and($user->fresh()?->getFirebaseUid())->toBe('uid-1');
});

it('links the same firebase user again without a second link', function () {
    $user = linkedUser(verified: false);

    app(LinkFirebaseIdentity::class)->handle($user, userRecord());

    expect(FirebaseIdentity::query()->count())->toBe(1)
        ->and($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('refuses to link a record linked to another uid', function () {
    $user = linkedUser(uid: 'uid-2');

    expect(fn () => app(LinkFirebaseIdentity::class)->handle($user, userRecord()))->toThrow(LogicException::class)
        ->and($user->fresh()?->getFirebaseUid())->toBe('uid-2');
});

it('deletes a firebase account', function () {
    mockFirebase()->shouldReceive('deleteUser')->once()->with('uid-1');

    app(DeleteFirebaseUser::class)->handle('uid-1');
});

it('treats a missing account as deleted', function () {
    mockFirebase()->shouldReceive('deleteUser')->once()->andThrow(new UserNotFound('No user'));

    app(DeleteFirebaseUser::class)->handle('uid-9');
});

it('refuses to link a disabled firebase user', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    expect(fn () => app(LinkFirebaseIdentity::class)->handle($user, userRecord(['disabled' => true])))
        ->toThrow(LogicException::class, 'disabled')
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('refuses to link a uid that another record of the model already has', function () {
    linkedUser(uid: 'uid-1');
    $other = User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

    expect(fn () => app(LinkFirebaseIdentity::class)->handle($other, userRecord()))
        ->toThrow(LogicException::class, 'another record')
        ->and(FirebaseIdentity::query()->count())->toBe(1);
});

it('links the same uid to records of different models', function () {
    linkedUser(uid: 'uid-1');
    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    app(LinkFirebaseIdentity::class)->handle($admin, userRecord());

    expect(FirebaseIdentity::query()->count())->toBe(2)
        ->and($admin->getFirebaseUid())->toBe('uid-1');
});

it('refuses to link an unsaved record', function () {
    expect(fn () => app(LinkFirebaseIdentity::class)->handle(new User, userRecord()))
        ->toThrow(LogicException::class)
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('sets the email verification in firebase', function () {
    mockFirebase()->shouldReceive('updateUser')->once()->with('uid-1', ['emailVerified' => true])->andReturn(userRecord());

    app(SetFirebaseEmailVerified::class)->handle('uid-1', true);
});
