<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Reads a Firebase user by email.
 *
 * The container keeps one instance per request, so each email is read once per request.
 */
class FindFirebaseUserByEmail
{
    /**
     * Firebase users by email. Null means that Firebase has no user with this email.
     *
     * @var array<string, UserRecord|null>
     */
    protected array $users = [];

    public function __construct(protected Auth $auth) {}

    /**
     * Returns the Firebase user, or null when Firebase has no user with this email.
     *
     * Other Firebase exceptions go to the caller. An exception is not remembered,
     * so the next call asks Firebase again.
     */
    public function handle(string $email): ?UserRecord
    {
        if (array_key_exists($email, $this->users)) {
            return $this->users[$email];
        }

        try {
            $user = $this->auth->getUserByEmail($email);
        } catch (UserNotFound) {
            $user = null;
        }

        return $this->users[$email] = $user;
    }
}
