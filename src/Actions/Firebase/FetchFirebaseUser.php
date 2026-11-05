<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Reads a Firebase user by UID, always from Firebase.
 *
 * Use it after a change in the same request. `FindFirebaseUser` remembers its answers and can
 * return the record from before the change.
 */
class FetchFirebaseUser
{
    public function __construct(protected Auth $auth) {}

    /**
     * Returns null when Firebase has no user with this UID. Other Firebase exceptions go to the caller.
     */
    public function handle(string $uid): ?UserRecord
    {
        try {
            return $this->auth->getUser($uid);
        } catch (UserNotFound) {
            return null;
        }
    }
}
