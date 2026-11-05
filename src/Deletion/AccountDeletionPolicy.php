<?php

namespace Isapp\FilamentFirebaseAuth\Deletion;

use DateTimeImmutable;
use Illuminate\Config\Repository;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Enums\DeleteAccounts;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Applies the `delete_accounts` config key.
 *
 * - `never`: deletes nothing.
 * - `new_only`: deletes an account that Firebase created during this sign-in, when a closed panel
 *   blocks it.
 * - `orphaned`: deletes an account that has no link left in this app.
 */
class AccountDeletionPolicy implements DecidesAccountDeletion
{
    public function __construct(protected Repository $config) {}

    /**
     * An unknown config value throws a ValueError. The policy never falls back to a default.
     */
    public function shouldDelete(UserRecord $firebaseUser, DeletionReason $reason): bool
    {
        return match (DeleteAccounts::from($this->config->string('filament-firebase-auth.delete_accounts'))) {
            DeleteAccounts::Never => false,
            DeleteAccounts::NewOnly => $reason === DeletionReason::BlockedOnClosedPanel && $this->isNew($firebaseUser),
            DeleteAccounts::Orphaned => FirebaseIdentity::query()->where('firebase_uid', $firebaseUser->uid)->doesntExist(),
        };
    }

    /**
     * Firebase created the account during this sign-in when `createdAt` and `lastLoginAt` are within
     * the window of each other and of now. This is a heuristic.
     */
    protected function isNew(UserRecord $firebaseUser): bool
    {
        $lastLoginAt = $firebaseUser->metadata->lastLoginAt;

        if (! $lastLoginAt instanceof DateTimeImmutable) {
            return false;
        }

        $window = $this->config->integer('filament-firebase-auth.new_account_window');
        $createdAt = $firebaseUser->metadata->createdAt->getTimestamp();

        return abs($createdAt - $lastLoginAt->getTimestamp()) <= $window
            && abs(now()->getTimestamp() - $createdAt) <= $window;
    }
}
