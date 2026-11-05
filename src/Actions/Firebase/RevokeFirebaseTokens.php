<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;

/**
 * Revokes the refresh tokens of a Firebase user.
 *
 * This signs the user out of every app on the same Firebase project.
 */
class RevokeFirebaseTokens
{
    public function __construct(protected Auth $auth) {}

    /**
     * Returns the user as Firebase stores it after the revocation, with the new `tokensValidAfterTime`.
     * It reads the user directly, because `FindFirebaseUser` can hold the record from before
     * the revocation. Firebase exceptions go to the caller.
     */
    public function handle(string $uid): UserRecord
    {
        $this->auth->revokeRefreshTokens($uid);

        return $this->auth->getUser($uid);
    }
}
