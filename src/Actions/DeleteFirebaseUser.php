<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\UserNotFound;

/**
 * Deletes a Firebase account.
 */
class DeleteFirebaseUser
{
    public function __construct(protected Auth $auth) {}

    /**
     * An account that does not exist counts as deleted. Other Firebase exceptions go to the caller.
     */
    public function handle(string $uid): void
    {
        try {
            $this->auth->deleteUser($uid);
        } catch (UserNotFound) {
            // Nothing to delete.
        }
    }
}
