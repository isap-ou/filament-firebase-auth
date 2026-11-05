<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;

/**
 * Sets the display name of a Firebase user.
 *
 * Firebase is the source of truth for the name. The sync on each sign-in copies it to the record.
 */
class SetFirebaseDisplayName
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions go to the caller.
     */
    public function handle(string $uid, string $name): UserRecord
    {
        return $this->auth->updateUser($uid, ['displayName' => $name]);
    }
}
