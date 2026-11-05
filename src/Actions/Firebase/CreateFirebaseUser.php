<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use SensitiveParameter;

/**
 * Creates a Firebase account with an email and a password.
 */
class CreateFirebaseUser
{
    public function __construct(protected Auth $auth) {}

    /**
     * Firebase exceptions (for example EmailExists) go to the caller.
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $name, bool $verified): UserRecord
    {
        $properties = [
            'email' => $email,
            'password' => $password,
            'emailVerified' => $verified,
        ];

        if ($name !== null && $name !== '') {
            $properties['displayName'] = $name;
        }

        return $this->auth->createUser($properties);
    }
}
