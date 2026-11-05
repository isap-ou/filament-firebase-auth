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
 * Copies the Firebase name and email to a linked record and refreshes the link columns.
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

        if ($firebaseUser->displayName !== null) {
            $user->setAttribute('name', $firebaseUser->displayName);
        }

        if ($firebaseUser->email !== null) {
            $user->setAttribute('email', $firebaseUser->email);
        }

        if ($user->isDirty(['name', 'email'])) {
            $user->save();
        }

        $this->refreshFirebaseIdentity->handle($identity, $firebaseUser);
    }
}
