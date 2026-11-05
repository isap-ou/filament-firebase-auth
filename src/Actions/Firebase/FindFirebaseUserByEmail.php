<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Reads a Firebase user by email.
 *
 * The container keeps one instance per request, so each found email is read once per request.
 * An unknown email is not remembered, because an account created later in the same request
 * (for example by `CreateFirebaseUser`) must be found by the next lookup.
 */
class FindFirebaseUserByEmail
{
    /**
     * The found Firebase users by email.
     *
     * @var array<string, UserRecord>
     */
    protected array $users = [];

    public function __construct(protected Auth $auth) {}

    /**
     * Returns the Firebase user, or null when Firebase has no user with this email.
     *
     * Other Firebase exceptions go to the caller. Only a found user is remembered,
     * so after an unknown email or an exception the next call asks Firebase again.
     */
    public function handle(string $email): ?UserRecord
    {
        if (isset($this->users[$email])) {
            return $this->users[$email];
        }

        try {
            return $this->users[$email] = $this->auth->getUserByEmail($email);
        } catch (UserNotFound) {
            return null;
        }
    }
}
