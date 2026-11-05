<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Psr\Log\LoggerInterface;

/**
 * Sets the Firebase verification flag for an email that a verification link proved.
 *
 * The link proves the local email only. Firebase can hold another email for the same UID until the
 * next sync, and the flag is shared with other apps on the same Firebase project. So the action reads
 * the Firebase user fresh and refuses when its email differs from the proven email. It then writes the
 * email and the flag in one call, so the flag never lands on another address.
 */
class VerifyFirebaseEmail
{
    public function __construct(
        protected FetchFirebaseUser $fetchFirebaseUser,
        protected ChangeFirebaseEmail $changeFirebaseEmail,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Returns false when Firebase has no user, no email, or another email. Firebase exceptions go to the caller.
     */
    public function handle(string $uid, string $provenEmail): bool
    {
        $firebaseUser = $this->fetchFirebaseUser->handle($uid);
        $firebaseEmail = $firebaseUser?->email;

        if ($firebaseEmail === null || mb_strtolower($firebaseEmail) !== mb_strtolower($provenEmail)) {
            $this->logger->warning('The verification link proved another email than the Firebase email.', [
                'uid' => $uid,
            ]);

            return false;
        }

        $this->changeFirebaseEmail->handle($uid, $firebaseEmail, verified: true);

        return true;
    }
}
