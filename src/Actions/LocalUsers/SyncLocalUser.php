<?php

namespace Isapp\FilamentFirebaseAuth\Actions\LocalUsers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Identity\RefreshFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

/**
 * Refreshes the link columns, then copies the Firebase name and email to a linked record.
 *
 * The link holds the session state (revoked tokens, a disabled account). It is written first, so a
 * failed record save (for example an email that another record holds) never stops the session check.
 * The verified flag rises only for the email that the record holds, so it waits for a successful save.
 */
class SyncLocalUser implements SyncsLocalUsers
{
    public function __construct(protected RefreshFirebaseIdentity $refreshFirebaseIdentity) {}

    /**
     * Refuses an unsaved record, a record without a link and a record linked to another Firebase user.
     * Only the owner of the link may write to the record.
     */
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void
    {
        if (! $user->exists) {
            throw new LogicException('Only a saved record can be synced.');
        }

        $identity = $user->getRelationValue('firebaseIdentity');

        if (! $identity instanceof FirebaseIdentity) {
            throw new LogicException('The record has no link to a Firebase user.');
        }

        if ($identity->firebase_uid !== $firebaseUser->uid) {
            throw new LogicException('The record is linked to another Firebase user.');
        }

        // The record still holds its old email here. The verified flag follows only after the save.
        $this->refreshFirebaseIdentity->handle($identity, $firebaseUser, (string) $user->getAttribute('email'));

        if ($firebaseUser->displayName !== null) {
            $user->setAttribute('name', $firebaseUser->displayName);
        }

        if ($firebaseUser->email !== null) {
            $user->setAttribute('email', $firebaseUser->email);
        }

        if (! $user->isDirty(['name', 'email'])) {
            return;
        }

        $emailChanged = $user->isDirty('email');

        $user->save();

        if ($emailChanged) {
            $this->refreshFirebaseIdentity->handle($identity, $firebaseUser, (string) $user->getAttribute('email'));
        }
    }
}
