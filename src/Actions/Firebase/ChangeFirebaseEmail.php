<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;

/**
 * Sets a new email and its verification flag for a Firebase user in one call.
 *
 * Firebase keeps the old verification flag when only the email changes. The plugin links a record
 * by email when the Firebase email is verified, so an unproven email must never keep the flag.
 */
class ChangeFirebaseEmail
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions (for example EmailExists) go to the caller.
     */
    public function handle(string $uid, string $email, bool $verified): UserRecord
    {
        return $this->auth->updateUser($uid, [
            'email' => $email,
            'emailVerified' => $verified,
        ]);
    }
}
