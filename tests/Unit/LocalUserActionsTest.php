<?php

use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\CreateLocalUser;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\LegacyUser;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;

use function Pest\Laravel\travel;

it('binds the contracts to the default actions', function () {
    expect(app(CreatesLocalUsers::class))->toBeInstanceOf(CreateLocalUser::class)
        ->and(app(SyncsLocalUsers::class))->toBeInstanceOf(SyncLocalUser::class);
});

it('creates a saved local user from a firebase user', function () {
    $user = app(CreatesLocalUsers::class)->handle(userRecord(['displayName' => 'Jane']), User::class, null);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and($user->getAttribute('name'))->toBe('Jane')
        ->and(User::query()->where('email', 'jane@example.com')->value('name'))->toBe('Jane');
});

it('uses the local part of the email when firebase has no display name', function () {
    $user = app(CreatesLocalUsers::class)->handle(userRecord(), User::class, null);

    expect($user->getAttribute('name'))->toBe('jane');
});

it('explains a not null password column', function () {
    expect(fn () => app(CreatesLocalUsers::class)->handle(userRecord(), LegacyUser::class, null))
        ->toThrow(LogicException::class, 'Prepare your users table');
});

it('syncs name, email, verification and token time', function () {
    $user = linkedUser(verified: false);
    $user->firebaseIdentity?->update(['invalidated_at' => now(), 'synced_at' => now()->subDay()]);

    app(SyncsLocalUsers::class)->handle($user, userRecord([
        'displayName' => 'Jane Doe',
        'email' => 'jane.doe@example.com',
        'emailVerified' => true,
        'validSince' => '1700000000',
    ]));

    $user->refresh();

    expect($user->getAttribute('name'))->toBe('Jane Doe')
        ->and($user->getAttribute('email'))->toBe('jane.doe@example.com')
        ->and($user->firebaseIdentity?->email_verified_at)->not->toBeNull()
        ->and($user->firebaseIdentity?->tokens_valid_after?->getTimestamp())->toBe(1700000000)
        ->and($user->firebaseIdentity?->invalidated_at)->toBeNull()
        ->and($user->firebaseIdentity?->synced_at?->isToday())->toBeTrue();
});

it('clears the local verification when firebase reports an unverified email', function () {
    $user = linkedUser(verified: true);

    app(SyncsLocalUsers::class)->handle($user, userRecord(['emailVerified' => false]));

    expect($user->refresh()->firebaseIdentity?->email_verified_at)->toBeNull();
});

it('keeps the local name when firebase has no display name', function () {
    $user = linkedUser();

    app(SyncsLocalUsers::class)->handle($user, userRecord());

    expect($user->refresh()->getAttribute('name'))->toBe('Jane');
});

it('does not save an unchanged record', function () {
    $user = linkedUser();
    $saves = 0;
    User::saving(function () use (&$saves): void {
        $saves++;
    });

    app(SyncsLocalUsers::class)->handle($user, userRecord(['displayName' => 'Jane']));

    expect($saves)->toBe(0);
});

it('refuses to sync a record linked to another uid', function () {
    $user = linkedUser(uid: 'uid-2');

    expect(fn () => app(SyncsLocalUsers::class)->handle($user, userRecord(['displayName' => 'Mallory'])))
        ->toThrow(LogicException::class);

    expect($user->refresh()->getAttribute('name'))->toBe('Jane');
});

it('refreshes the link before a record save that fails', function () {
    // Another app gave the Firebase account an email that another local record holds.
    User::query()->create(['name' => 'John', 'email' => 'taken@example.com']);
    $user = linkedUser();
    $user->firebaseIdentity?->update(['tokens_valid_after' => now()->subHour()]);

    expect(fn () => app(SyncsLocalUsers::class)->handle($user, userRecord([
        'email' => 'taken@example.com',
        'validSince' => '1700000000',
    ])))->toThrow(QueryException::class);

    expect($user->firebaseIdentity?->fresh()?->tokens_valid_after?->getTimestamp())->toBe(1700000000);
});

it('does not raise the verified flag when the record save fails', function () {
    User::query()->create(['name' => 'John', 'email' => 'taken@example.com']);
    $user = linkedUser(verified: false);

    expect(fn () => app(SyncsLocalUsers::class)->handle($user, userRecord([
        'email' => 'taken@example.com',
        'emailVerified' => true,
    ])))->toThrow(QueryException::class);

    expect($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
});

it('keeps other database errors as they are', function () {
    app(CreatesLocalUsers::class)->handle(userRecord(), User::class, null);

    expect(fn () => app(CreatesLocalUsers::class)->handle(userRecord(), User::class, null))
        ->toThrow(QueryException::class);
});

it('invalidates the link of a disabled firebase user', function () {
    $user = linkedUser();

    app(SyncsLocalUsers::class)->handle($user, userRecord(['disabled' => true]));
    $invalidatedAt = $user->refresh()->firebaseIdentity?->invalidated_at;

    travel(1)->hour();
    app(SyncsLocalUsers::class)->handle($user, userRecord(['disabled' => true]));

    expect($invalidatedAt)->not->toBeNull()
        ->and($user->refresh()->firebaseIdentity?->invalidated_at?->getTimestamp())->toBe($invalidatedAt?->getTimestamp());
});

it('refuses to sync a record without a link', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    expect(fn () => app(SyncsLocalUsers::class)->handle($user, userRecord(['displayName' => 'Mallory'])))
        ->toThrow(LogicException::class);

    expect($user->refresh()->getAttribute('name'))->toBe('Jane');
});

it('refuses to sync an unsaved record', function () {
    expect(fn () => app(SyncsLocalUsers::class)->handle(new User, userRecord()))
        ->toThrow(LogicException::class)
        ->and(User::query()->count())->toBe(0);
});

it('saves only a change of name or email', function () {
    $user = linkedUser();
    $user->setAttribute('remember_token', 'changed-elsewhere');
    $saves = 0;
    User::saving(function () use (&$saves): void {
        $saves++;
    });

    app(SyncsLocalUsers::class)->handle($user, userRecord(['displayName' => 'Jane']));

    expect($saves)->toBe(0);
});
