<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Isapp\FilamentFirebaseAuth\Actions\Firebase\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Kreait\Firebase\Auth\UserRecord;
use Psr\Log\LoggerInterface;

/**
 * Deletes a Firebase account when the deletion policy allows it.
 */
class DeleteFirebaseUserIfAllowed
{
    public function __construct(
        protected DecidesAccountDeletion $decidesAccountDeletion,
        protected DeleteFirebaseUser $deleteFirebaseUser,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Returns true when the account was deleted. Firebase exceptions go to the caller.
     */
    public function handle(UserRecord $firebaseUser, DeletionReason $reason): bool
    {
        if (! $this->decidesAccountDeletion->shouldDelete($firebaseUser, $reason)) {
            return false;
        }

        $this->deleteFirebaseUser->handle($firebaseUser->uid);

        // The log names the UID only. It never contains the email or other personal data.
        $this->logger->info('Deleted the Firebase account.', ['uid' => $firebaseUser->uid]);

        return true;
    }
}
