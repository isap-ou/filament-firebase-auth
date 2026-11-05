<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use SensitiveParameter;

/**
 * Sets a new password and marks the email as verified, in one Firebase request.
 *
 * One request means that Firebase never marks the email as verified while the old password
 * still works. The password and the flag are shared with other apps on the same Firebase project.
 */
class ResetFirebasePassword
{
    public function __construct(protected Auth $auth) {}

    /**
     * Returns the Firebase user after the change. Firebase exceptions go to the caller.
     */
    public function handle(string $uid, #[SensitiveParameter] string $password): UserRecord
    {
        return $this->auth->updateUser($uid, ['password' => $password, 'emailVerified' => true]);
    }
}
