<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use SensitiveParameter;

/**
 * Sets a new password for a Firebase user.
 */
class ChangeFirebasePassword
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions go to the caller.
     */
    public function handle(string $uid, #[SensitiveParameter] string $password): UserRecord
    {
        return $this->auth->changeUserPassword($uid, $password);
    }
}
