<?php

use Isapp\FilamentFirebaseAuth\Actions\Identity\PersistResolvedUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\ResolveLocalUser;
use Isapp\FilamentFirebaseAuth\Data\ResolvedUser;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;

it('uses the linked record', function () {
    $user = linkedUser();

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['email' => 'new@example.com']), User::class, false);

    expect($resolved->resolution)->toBe(Resolution::Linked)
        ->and($resolved->user)->toBeInstanceOf(User::class)
        ->and($resolved->user?->getKey())->toBe($user->getKey());
});

it('uses only a link of the same model', function () {
    linkedUser();

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['email' => 'other@example.com']), Admin::class, false);

    expect($resolved->resolution)->toBe(Resolution::Blocked);
});

it('links by email when firebase verified the email', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(), User::class, false);

    expect($resolved->resolution)->toBe(Resolution::LinkedByEmail)
        ->and($resolved->user?->getKey())->toBe($user->getKey())
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('refuses to link an unverified email', function () {
    User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['emailVerified' => false]), User::class, true);

    expect($resolved->resolution)->toBe(Resolution::Blocked)
        ->and($resolved->user)->toBeNull()
        ->and(FirebaseIdentity::query()->count())->toBe(0);
});

it('refuses a record linked to another uid', function () {
    linkedUser(uid: 'uid-old');

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['localId' => 'uid-new']), User::class, true);

    expect($resolved->resolution)->toBe(Resolution::Blocked)
        ->and($resolved->user)->toBeNull()
        ->and(FirebaseIdentity::query()->count())->toBe(1)
        ->and(FirebaseIdentity::query()->value('firebase_uid'))->toBe('uid-old')
        ->and(User::query()->count())->toBe(1);
});

it('blocks a disabled firebase user', function () {
    linkedUser();
    User::query()->create(['name' => 'John', 'email' => 'john@example.com']);

    $resolver = app(ResolveLocalUser::class);

    expect($resolver->handle(userRecord(['disabled' => true]), User::class, true)->resolution)->toBe(Resolution::Blocked)
        ->and($resolver->handle(userRecord(['localId' => 'uid-2', 'email' => 'john@example.com', 'disabled' => true]), User::class, true)->resolution)
        ->toBe(Resolution::Blocked)
        ->and($resolver->handle(userRecord(['localId' => 'uid-3', 'email' => 'new@example.com', 'disabled' => true]), User::class, true)->resolution)
        ->toBe(Resolution::Blocked);
});

it('blocks a linked record that is soft deleted', function () {
    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $admin->firebaseIdentity()->create(['firebase_uid' => 'uid-1']);
    $admin->delete();

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(), Admin::class, true);

    expect($resolved->resolution)->toBe(Resolution::Blocked)
        ->and(Admin::withTrashed()->count())->toBe(1);
});

it('offers an unsaved record on an open panel', function () {
    $resolved = app(ResolveLocalUser::class)->handle(userRecord(), User::class, true);

    expect($resolved->resolution)->toBe(Resolution::New)
        ->and($resolved->user)->toBeInstanceOf(User::class)
        ->and($resolved->user instanceof User && ! $resolved->user->exists)->toBeTrue()
        ->and($resolved->user?->getAttribute('email'))->toBe('jane@example.com')
        ->and(User::query()->count())->toBe(0);
});

it('blocks on a closed panel', function () {
    $resolved = app(ResolveLocalUser::class)->handle(userRecord(), User::class, false);

    expect($resolved->resolution)->toBe(Resolution::Blocked)
        ->and($resolved->user)->toBeNull();
});

it('persists a new record and its link', function () {
    $firebaseUser = userRecord(['displayName' => 'Jane Doe']);
    $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, User::class, true);

    $user = app(PersistResolvedUser::class)->handle($resolved, $firebaseUser, null);

    $saved = User::query()->sole();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getKey())->toBe($saved->getKey())
        ->and($saved->getAttribute('name'))->toBe('Jane Doe')
        ->and($saved->getFirebaseUid())->toBe('uid-1')
        ->and($saved->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->count())->toBe(1)
        ->and(FirebaseIdentity::query()->count())->toBe(1);
});

it('persists the link of a record found by email', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $firebaseUser = userRecord(['displayName' => 'Jane Doe']);
    $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, User::class, false);

    $persisted = app(PersistResolvedUser::class)->handle($resolved, $firebaseUser, null);

    $user->refresh();

    expect($persisted->getKey())->toBe($user->getKey())
        ->and($user->getFirebaseUid())->toBe('uid-1')
        ->and($user->getAttribute('name'))->toBe('Jane Doe');
});

it('syncs a linked record when it persists', function () {
    linkedUser();
    $firebaseUser = userRecord(['displayName' => 'Jane Doe']);
    $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, User::class, false);

    $persisted = app(PersistResolvedUser::class)->handle($resolved, $firebaseUser, null);

    expect($persisted->fresh()?->getAttribute('name'))->toBe('Jane Doe')
        ->and(FirebaseIdentity::query()->count())->toBe(1);
});

it('refuses to persist a blocked resolution', function () {
    expect(fn () => app(PersistResolvedUser::class)->handle(new ResolvedUser(Resolution::Blocked, null), userRecord(), null))
        ->toThrow(LogicException::class);
});

it('rolls back the new record when the link fails', function () {
    linkedUser(uid: 'uid-1', email: 'old@example.com');
    $firebaseUser = userRecord();
    $resolved = new ResolvedUser(Resolution::New, new User);

    expect(fn () => app(PersistResolvedUser::class)->handle($resolved, $firebaseUser, null))
        ->toThrow(LogicException::class)
        ->and(User::query()->where('email', 'jane@example.com')->exists())->toBeFalse();
});

it('blocks a firebase user without an email when no link exists', function () {
    // For example, a Twitter account without the email permission. A record needs an email.
    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['email' => null, 'emailVerified' => false]), User::class, true);

    expect($resolved->resolution)->toBe(Resolution::Blocked)
        ->and(User::query()->count())->toBe(0);
});

it('uses the linked record of a firebase user without an email', function () {
    $user = linkedUser();

    $resolved = app(ResolveLocalUser::class)->handle(userRecord(['email' => null]), User::class, true);

    expect($resolved->resolution)->toBe(Resolution::Linked)
        ->and($resolved->user?->getKey())->toBe($user->getKey());
});
