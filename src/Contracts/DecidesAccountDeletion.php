<?php

namespace Isapp\FilamentFirebaseAuth\Contracts;

use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Decides if the plugin deletes a Firebase account.
 *
 * The default reads the `delete_accounts` config key. A host app can bind its own class.
 */
interface DecidesAccountDeletion
{
    public function shouldDelete(UserRecord $firebaseUser, DeletionReason $reason): bool;
}
