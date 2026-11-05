<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Reads a Firebase user by UID.
 *
 * The container keeps one instance per request, so each UID is read once per request.
 */
class FindFirebaseUser
{
    /**
     * Firebase users by UID. Null means that Firebase has no user with this UID.
     *
     * @var array<string, UserRecord|null>
     */
    protected array $users = [];

    public function __construct(protected Auth $auth) {}

    /**
     * Returns the Firebase user, or null when Firebase has no user with this UID.
     *
     * Other Firebase exceptions (for example a connection failure) go to the caller.
     * An exception is not remembered, so the next call asks Firebase again.
     */
    public function handle(string $uid): ?UserRecord
    {
        if (array_key_exists($uid, $this->users)) {
            return $this->users[$uid];
        }

        try {
            $user = $this->auth->getUser($uid);
        } catch (UserNotFound) {
            $user = null;
        }

        return $this->users[$uid] = $user;
    }
}
