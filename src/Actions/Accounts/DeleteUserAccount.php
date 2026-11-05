<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Enums\DeleteAccounts;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes the local record and its link when the person deletes the account on the profile page.
 * Then the deletion policy runs once, with the reason `UserDeletedAccount`.
 */
class DeleteUserAccount
{
    public function __construct(
        protected FindFirebaseUser $findFirebaseUser,
        protected DeleteFirebaseUserIfAllowed $deleteFirebaseUserIfAllowed,
        protected Repository $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * A model with soft deletes keeps a soft-deleted record. The link is always deleted.
     *
     * The Firebase part runs after the open transaction commits. A failure in the Firebase part
     * never stops the deletion of the local record. The plugin logs the UID and the exception class.
     */
    public function handle(Model & Authenticatable $user): void
    {
        $identity = $user->getRelationValue('firebaseIdentity');
        $identity = $identity instanceof FirebaseIdentity ? $identity : null;
        $uid = $identity?->firebase_uid;

        $user->getConnection()->transaction(function () use ($user, $identity): void {
            // The link goes first. The delete hook of HasFirebaseIdentity then finds no link,
            // so it does not run the policy a second time with the reason `RecordDeleted`.
            $identity?->delete();
            $user->unsetRelation('firebaseIdentity');

            $user->delete();
        });

        if ($uid === null || DeleteAccounts::from($this->config->string('filament-firebase-auth.delete_accounts')) === DeleteAccounts::Never) {
            return;
        }

        // Without an open transaction, Laravel runs the callback at once.
        $user->getConnection()->afterCommit(function () use ($uid): void {
            try {
                $firebaseUser = $this->findFirebaseUser->handle($uid);

                if ($firebaseUser instanceof UserRecord) {
                    $this->deleteFirebaseUserIfAllowed->handle($firebaseUser, DeletionReason::UserDeletedAccount);
                }
            } catch (Throwable $exception) {
                $this->logger->warning('The Firebase part of an account deletion failed.', [
                    'uid' => $uid,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
