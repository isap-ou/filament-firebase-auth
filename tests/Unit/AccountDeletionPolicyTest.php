<?php

use Illuminate\Support\Facades\Log;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DeleteFirebaseUserIfAllowed;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Deletion\AccountDeletionPolicy;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * A Firebase user whose account and last sign-in are the given number of seconds old.
 */
function firebaseUserAged(int $createdSecondsAgo, int $lastLoginSecondsAgo): UserRecord
{
    return userRecord([
        'createdAt' => (string) now()->subSeconds($createdSecondsAgo)->getTimestampMs(),
        'lastLoginAt' => (string) now()->subSeconds($lastLoginSecondsAgo)->getTimestampMs(),
    ]);
}

it('binds the contract to the default policy', function () {
    expect(app(DecidesAccountDeletion::class))->toBeInstanceOf(AccountDeletionPolicy::class);
});

it('never deletes in never mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'never');
    $policy = app(DecidesAccountDeletion::class);

    foreach (DeletionReason::cases() as $reason) {
        expect($policy->shouldDelete(firebaseUserAged(5, 5), $reason))->toBeFalse();
    }
});

it('deletes a fresh account on a closed panel in new_only mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'new_only');
    config()->set('filament-firebase-auth.new_account_window', 60);

    expect(app(DecidesAccountDeletion::class)->shouldDelete(firebaseUserAged(5, 5), DeletionReason::BlockedOnClosedPanel))
        ->toBeTrue();
});

it('keeps an old account in new_only mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'new_only');
    config()->set('filament-firebase-auth.new_account_window', 60);
    $policy = app(DecidesAccountDeletion::class);

    expect($policy->shouldDelete(firebaseUserAged(86400, 5), DeletionReason::BlockedOnClosedPanel))->toBeFalse()
        ->and($policy->shouldDelete(firebaseUserAged(86400, 86400), DeletionReason::BlockedOnClosedPanel))->toBeFalse();
});

it('keeps an account in new_only mode for other reasons', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'new_only');
    config()->set('filament-firebase-auth.new_account_window', 60);
    $policy = app(DecidesAccountDeletion::class);

    expect($policy->shouldDelete(firebaseUserAged(5, 5), DeletionReason::UserDeletedAccount))->toBeFalse()
        ->and($policy->shouldDelete(firebaseUserAged(5, 5), DeletionReason::RecordDeleted))->toBeFalse();
});

it('deletes only orphans in orphaned mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $policy = app(DecidesAccountDeletion::class);

    expect($policy->shouldDelete(userRecord(), DeletionReason::UserDeletedAccount))->toBeTrue();

    Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com'])
        ->firebaseIdentity()->create(['firebase_uid' => 'uid-1']);

    expect($policy->shouldDelete(userRecord(), DeletionReason::UserDeletedAccount))->toBeFalse();
});

it('fails loudly on an unknown delete_accounts value', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'sometimes');

    expect(fn () => app(DecidesAccountDeletion::class)->shouldDelete(userRecord(), DeletionReason::RecordDeleted))
        ->toThrow(ValueError::class);
});

it('deletes the firebase account when the policy allows it and logs the uid', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $log = Log::spy();
    mockFirebase()->shouldReceive('deleteUser')->once()->with('uid-1');

    expect(app(DeleteFirebaseUserIfAllowed::class)->handle(userRecord(), DeletionReason::UserDeletedAccount))->toBeTrue();

    $log->shouldHaveReceived('info')->once()->with('Deleted the Firebase account.', ['uid' => 'uid-1']);
});

it('keeps the firebase account when the policy refuses', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'never');
    mockFirebase()->shouldNotReceive('deleteUser');

    expect(app(DeleteFirebaseUserIfAllowed::class)->handle(userRecord(), DeletionReason::UserDeletedAccount))->toBeFalse();
});

it('deletes the link and asks the policy when a record is deleted', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());
    $firebase->shouldReceive('deleteUser')->once()->with('uid-1');

    linkedUser()->delete();

    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('keeps the firebase account of a deleted record in never mode', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'never');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->andReturn(userRecord());
    $firebase->shouldNotReceive('deleteUser');

    linkedUser()->delete();

    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('does not ask the policy when the firebase user is already gone', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->andThrow(new UserNotFound('No user'));
    $firebase->shouldNotReceive('deleteUser');

    linkedUser()->delete();

    expect(FirebaseIdentity::query()->count())->toBe(0);
});

it('deletes the record when firebase is unreachable and logs the uid', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $log = Log::spy();
    mockFirebase()->shouldReceive('getUser')->once()->andThrow(new ApiConnectionFailed('Firebase is down'));

    $user = linkedUser();
    $user->delete();

    expect($user->exists)->toBeFalse()
        ->and(FirebaseIdentity::query()->count())->toBe(0);

    $log->shouldHaveReceived('warning')->once()->with(
        'The Firebase account of a deleted record was not checked: Firebase failed.',
        ['uid' => 'uid-1'],
    );
});

it('does not call firebase for a deleted record without a link', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');

    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $admin->forceDelete();

    expect($admin->exists)->toBeFalse();
});

it('waits for force delete on soft deleting models', function () {
    config()->set('filament-firebase-auth.delete_accounts', 'orphaned');
    $calls = [];
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->andReturnUsing(function (string $uid) use (&$calls) {
        $calls[] = 'getUser ' . $uid;

        return userRecord();
    });
    $firebase->shouldReceive('deleteUser')->andReturnUsing(function (string $uid) use (&$calls): void {
        $calls[] = 'deleteUser ' . $uid;
    });

    $admin = Admin::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);
    $admin->firebaseIdentity()->create(['firebase_uid' => 'uid-1']);

    $admin->delete();

    expect(FirebaseIdentity::query()->count())->toBe(1)
        ->and($calls)->toBe([]);

    $admin->forceDelete();

    expect(FirebaseIdentity::query()->count())->toBe(0)
        ->and($calls)->toBe(['getUser uid-1', 'deleteUser uid-1']);
});
