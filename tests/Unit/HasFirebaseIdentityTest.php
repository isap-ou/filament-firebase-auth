<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\UserNotFound;

it('reads verification from the link without calling firebase', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');

    expect(linkedUser(verified: true)->hasVerifiedEmail())->toBeTrue();
});

it('reports an unverified email from the link', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');

    expect(linkedUser(verified: false)->hasVerifiedEmail())->toBeFalse()
        ->and((new User)->hasVerifiedEmail())->toBeFalse();
});

it('marks the email as verified in firebase and locally', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'jane@example.com', 'emailVerified' => true])->andReturn(userRecord());

    $user = linkedUser(verified: false);

    expect($user->markEmailAsVerified())->toBeTrue()
        ->and($user->firebaseIdentity?->email_verified_at)->not->toBeNull()
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->not->toBeNull();
});

it('compares the local and the firebase email without case when it marks the email as verified', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'jane@example.com', 'emailVerified' => true])->andReturn(userRecord());

    $user = linkedUser(verified: false, email: 'Jane@Example.com');

    expect($user->markEmailAsVerified())->toBeTrue();
});

it('refuses to mark the email as verified when the firebase email differs from the local email', function () {
    // The verification link proves the local email only. Firebase can hold another email until the next sync.
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')
        ->andReturn(userRecord(['email' => 'boss@example.com', 'emailVerified' => false]));
    $firebase->shouldNotReceive('updateUser');

    $user = linkedUser(verified: false);

    expect($user->markEmailAsVerified())->toBeFalse()
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
});

it('refuses to mark the email as verified when firebase has no email or no user', function (Closure $answer) {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturnUsing($answer);
    $firebase->shouldNotReceive('updateUser');

    $user = linkedUser(verified: false);

    expect($user->markEmailAsVerified())->toBeFalse()
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
})->with([
    'no email' => fn () => userRecord(['email' => null, 'emailVerified' => false]),
    'no user' => fn () => throw new UserNotFound('No user'),
]);

it('keeps the local flag when firebase fails to mark the email as verified', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()->andThrow(new ApiConnectionFailed('Firebase is down'));

    $user = linkedUser(verified: false);

    expect(fn () => $user->markEmailAsVerified())->toThrow(ApiConnectionFailed::class)
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
});

it('keeps the local flag when firebase fails to mark the email as unverified', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('updateUser')->once()->andThrow(new ApiConnectionFailed('Firebase is down'));

    $user = linkedUser(verified: true);

    expect(fn () => $user->markEmailAsUnverified())->toThrow(ApiConnectionFailed::class)
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->not->toBeNull();
});

it('marks the email as unverified in firebase and locally', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('updateUser')->once()->with('uid-1', ['emailVerified' => false])
        ->andReturn(userRecord(['emailVerified' => false]));

    $user = linkedUser(verified: true);

    expect($user->markEmailAsUnverified())->toBeTrue()
        ->and($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
});

it('does not call firebase to verify a record without a link', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('updateUser');

    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    expect($user->markEmailAsVerified())->toBeFalse();
});

it('never writes the password column', function () {
    $user = linkedUser();

    $user->forceFill(['password' => 'hash'])->save();

    expect(DB::table('users')->value('password'))->toBeNull();
});

it('never writes the password column on insert', function () {
    User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'hash']);

    expect(DB::table('users')->value('password'))->toBeNull();
});

it('returns the uid of the link', function () {
    expect(linkedUser(uid: 'uid-7')->getFirebaseUid())->toBe('uid-7');
});

it('returns null uid for a record without a link', function () {
    $user = User::query()->create(['name' => 'Jane', 'email' => 'jane@example.com']);

    expect($user->getFirebaseUid())->toBeNull()
        ->and((new User)->getFirebaseUid())->toBeNull();
});

it('changes the fingerprint when tokens are revoked, the account is invalidated or the salt rotates', function () {
    $user = linkedUser();
    $fingerprints = [$user->getAuthPassword()];

    $user->firebaseIdentity?->update(['tokens_valid_after' => now()]);
    $fingerprints[] = $user->refresh()->getAuthPassword();

    $user->firebaseIdentity?->update(['invalidated_at' => now()]);
    $fingerprints[] = $user->refresh()->getAuthPassword();

    $user->rotateSessionSalt();
    $fingerprints[] = $user->getAuthPassword();
    $fingerprints[] = $user->refresh()->getAuthPassword();

    expect(array_unique(array_slice($fingerprints, 0, 4)))->toHaveCount(4)
        ->and($fingerprints[4])->toBe($fingerprints[3]);
});

it('keeps the fingerprint stable while the link does not change', function () {
    $user = linkedUser();

    expect($user->getAuthPassword())->toBe($user->refresh()->getAuthPassword());
});

it('gives different uids different fingerprints', function () {
    expect(linkedUser(uid: 'uid-1')->getAuthPassword())
        ->not->toBe(linkedUser(uid: 'uid-2', email: 'john@example.com')->getAuthPassword());
});

it('never returns an empty fingerprint', fn () => expect((new User)->getAuthPassword())->not->toBeEmpty());

it('detects the firebase password provider and asks firebase once', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord());

    $user = linkedUser();

    expect($user->hasFirebasePasswordProvider())->toBeTrue()
        ->and($user->hasFirebasePasswordProvider())->toBeTrue();
});

it('reports no password provider for a social-only firebase user', function () {
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord([
        'providerUserInfo' => [
            ['providerId' => 'google.com', 'rawId' => 'g-1', 'email' => 'jane@example.com'],
        ],
    ]));

    expect(linkedUser()->hasFirebasePasswordProvider())->toBeFalse();
});

it('asks firebase again for the password provider after an error', function () {
    $calls = 0;
    mockFirebase()->shouldReceive('getUser')->twice()->with('uid-1')->andReturnUsing(function () use (&$calls) {
        if ($calls++ === 0) {
            throw new ApiConnectionFailed('Firebase is down');
        }

        return userRecord();
    });

    $user = linkedUser();

    expect(fn () => $user->hasFirebasePasswordProvider())->toThrow(ApiConnectionFailed::class)
        ->and($user->hasFirebasePasswordProvider())->toBeTrue();
});

it('reports no password provider for a deleted firebase user', function () {
    mockFirebase()->shouldReceive('getUser')->once()->andThrow(new UserNotFound('No user'));

    expect(linkedUser()->hasFirebasePasswordProvider())->toBeFalse();
});

it('reports no password provider for a record without a link', function () {
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');

    expect((new User)->hasFirebasePasswordProvider())->toBeFalse();
});

it('returns the host email for verification and password reset', function () {
    $user = linkedUser();

    expect($user->getEmailForVerification())->toBe('jane@example.com')
        ->and($user->getEmailForPasswordReset())->toBe('jane@example.com');
});

it('sends the laravel verification and reset notifications', function () {
    Notification::fake();
    $user = linkedUser();

    $user->sendEmailVerificationNotification();
    $user->sendPasswordResetNotification('token');

    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertSentTo($user, ResetPassword::class);
});

it('makes the fixture models verifiable and resettable', function () {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class)->toBeInstanceOf(CanResetPassword::class)
        ->and(new Admin)->toBeInstanceOf(MustVerifyEmail::class)->toBeInstanceOf(CanResetPassword::class);
});
