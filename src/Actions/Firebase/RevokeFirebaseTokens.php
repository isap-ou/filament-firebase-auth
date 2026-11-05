<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

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
     * Firebase exceptions go to the caller.
     */
    public function handle(string $uid): void
    {
        $this->auth->revokeRefreshTokens($uid);
    }
}
