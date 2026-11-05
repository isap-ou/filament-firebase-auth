<?php

namespace Isapp\FilamentFirebaseAuth\Enums;

/**
 * Why the plugin asks the deletion policy about a Firebase account.
 */
enum DeletionReason
{
    /**
     * The panel uses `createOnSignIn(false)` and blocked a Firebase user without a local record.
     */
    case BlockedOnClosedPanel;

    /**
     * The user deleted the account on the profile page.
     */
    case UserDeletedAccount;

    /**
     * Code or an admin deleted the local record.
     */
    case RecordDeleted;
}
