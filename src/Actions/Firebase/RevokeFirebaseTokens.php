<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Contract\Auth;

/**
 * Revokes the refresh tokens of a Firebase user. Firebase then rejects older ID tokens on a
 * revocation check, also in other apps on the same Firebase project.
 */
class RevokeFirebaseTokens
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions go to the caller.
     */
    public function handle(string $uid): void
    {
        $this->auth->revokeRefreshTokens($uid);
    }
}
