<?php

use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;

it('creates the link table with both unique indexes', function () {
    $link = fn (array $attributes) => (new FirebaseIdentity)->forceFill($attributes)->save();

    $link(['firebase_uid' => 'u1', 'authenticatable_type' => User::class, 'authenticatable_id' => 1]);

    expect(fn () => $link(['firebase_uid' => 'u1', 'authenticatable_type' => User::class, 'authenticatable_id' => 2]))
        ->toThrow(QueryException::class);
    expect(fn () => $link(['firebase_uid' => 'u2', 'authenticatable_type' => User::class, 'authenticatable_id' => 1]))
        ->toThrow(QueryException::class);

    $link(['firebase_uid' => 'u1', 'authenticatable_type' => Admin::class, 'authenticatable_id' => 1]);

    expect(FirebaseIdentity::count())->toBe(2);
});

it('uses the table name from config', function () {
    expect((new FirebaseIdentity)->getTable())->toBe('firebase_identities');

    config()->set('filament-firebase-auth.table', 'custom_identities');

    expect((new FirebaseIdentity)->getTable())->toBe('custom_identities');
});

it('finds a link by uid and morph type', function () {
    $identity = (new FirebaseIdentity)->forceFill([
        'firebase_uid' => 'u1',
        'authenticatable_type' => User::class,
        'authenticatable_id' => 1,
    ]);
    $identity->save();

    expect(FirebaseIdentity::query()->forFirebaseUid('u1', User::class)->first()?->id)->toBe($identity->id);
    expect(FirebaseIdentity::query()->forFirebaseUid('u1', Admin::class)->first())->toBeNull();
    expect(FirebaseIdentity::query()->forFirebaseUid('u2', User::class)->first())->toBeNull();
});

it('casts both timestamps to dates', function () {
    $identity = new FirebaseIdentity([
        'firebase_uid' => 'u1',
        'email_verified_at' => '2026-01-02 03:04:05',
        'synced_at' => '2026-01-03 03:04:05',
    ]);

    expect($identity->firebase_uid)->toBe('u1')
        ->and($identity->email_verified_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($identity->synced_at)->toBeInstanceOf(DateTimeInterface::class);
});

it('keeps the morph columns out of mass assignment', function () {
    $identity = new FirebaseIdentity([
        'firebase_uid' => 'u1',
        'authenticatable_type' => User::class,
        'authenticatable_id' => 1,
    ]);

    expect($identity->authenticatable_type)->toBeNull()
        ->and($identity->authenticatable_id)->toBeNull();
});

it('resolves the linked record through the morph relation', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    $identity = (new FirebaseIdentity)->forceFill(['firebase_uid' => 'u1']);
    $identity->authenticatable()->associate($user);
    $identity->save();

    $linked = $identity->fresh()?->authenticatable;

    expect($linked)->toBeInstanceOf(User::class)
        ->and($linked?->getKey())->toBe($user->getKey());
});
