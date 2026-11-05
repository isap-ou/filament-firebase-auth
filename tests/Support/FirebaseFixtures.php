<?php

use Kreait\Firebase\Auth\SignInResult;
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
 * Bind a mock of the Firebase Auth contract into the container and return it.
 */
function mockFirebase(): MockInterface
{
    $mock = Mockery::mock(Auth::class);

    app()->instance(Auth::class, $mock);

    return $mock;
}
