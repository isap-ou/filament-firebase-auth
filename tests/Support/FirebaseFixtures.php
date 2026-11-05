<?php

use GuzzleHttp\Psr7\Response;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Kreait\Firebase\Auth\SignIn\FailedToSignIn;
use Kreait\Firebase\Auth\SignInResult;
use Kreait\Firebase\Auth\SignInWithEmailAndPassword;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Mockery\MockInterface;

/**
 * Build a Firebase user record from response data. The defaults describe a verified
 * email and password user. Top-level keys in $overrides replace the defaults.
 *
 * @param  array<string, mixed>  $overrides
 */
function userRecord(array $overrides = []): UserRecord
{
    $now = now();

    return UserRecord::fromResponseData(array_merge([
        'localId' => 'uid-1',
        'email' => 'jane@example.com',
        'emailVerified' => true,
        'providerUserInfo' => [
            ['providerId' => 'password', 'rawId' => 'jane@example.com', 'email' => 'jane@example.com'],
        ],
        'createdAt' => (string) $now->getTimestampMs(),
        'lastLoginAt' => (string) $now->getTimestampMs(),
        'validSince' => (string) $now->getTimestamp(),
    ], $overrides));
}

/**
 * Build a Firebase sign-in result for the given UID.
 */
function signInResult(string $uid): SignInResult
{
    return SignInResult::fromData(['localId' => $uid]);
}

/**
 * Build the sign-in failure that Firebase gives for an HTTP error response.
 * A 4xx status rejects the credentials. A 5xx status means that Firebase failed.
 */
function failedSignIn(int $status, string $reason): FailedToSignIn
{
    return FailedToSignIn::withActionAndResponse(
        SignInWithEmailAndPassword::fromValues('jane@example.com', 'secret'),
        new Response($status, [], '{"error":{"message":"' . $reason . '"}}'),
    );
}

/**
 * Create a saved fixture user with a saved link to the given Firebase UID.
 */
function linkedUser(bool $verified = true, string $uid = 'uid-1', string $email = 'jane@example.com'): User
{
    $user = User::query()->create(['name' => 'Jane', 'email' => $email]);

    $user->firebaseIdentity()->create([
        'firebase_uid' => $uid,
        'email_verified_at' => $verified ? now() : null,
        'synced_at' => now(),
    ]);

    return $user->load('firebaseIdentity');
}

/**
 * Bind a mock of the Firebase Auth contract into the container and return it.
 */
function mockFirebase(): MockInterface
{
    $mock = Mockery::mock(Auth::class);

    app()->instance(Auth::class, $mock);

    return $mock;
}
