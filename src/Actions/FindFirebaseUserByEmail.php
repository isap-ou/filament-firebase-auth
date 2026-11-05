<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Reads a Firebase user by email.
 */
class FindFirebaseUserByEmail
{
    public function __construct(protected Auth $auth) {}

    /**
     * Returns the Firebase user, or null when Firebase has no user with this email.
     *
     * Other Firebase exceptions go to the caller.
     */
    public function handle(string $email): ?UserRecord
    {
        try {
            return $this->auth->getUserByEmail($email);
        } catch (UserNotFound) {
            return null;
        }
    }
}
