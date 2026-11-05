<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Kreait\Firebase\Contract\Auth;

/**
 * Sets the email verification flag of a Firebase user.
 *
 * The flag is shared with other apps on the same Firebase project.
 */
class SetFirebaseEmailVerified
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions go to the caller.
     */
    public function handle(string $uid, bool $verified): void
    {
        $this->auth->updateUser($uid, ['emailVerified' => $verified]);
    }
}
