<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

/**
 * Copies the Firebase name and email to the record and refreshes the link columns.
 */
class SyncLocalUser implements SyncsLocalUsers
{
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void
    {
        $identity = $user->getRelationValue('firebaseIdentity');

        if ($identity instanceof FirebaseIdentity && $identity->firebase_uid !== $firebaseUser->uid) {
            throw new LogicException('The record is linked to another Firebase user.');
        }

        if ($firebaseUser->displayName !== null) {
            $user->setAttribute('name', $firebaseUser->displayName);
        }

        if ($firebaseUser->email !== null) {
            $user->setAttribute('email', $firebaseUser->email);
        }

        if ($user->isDirty()) {
            $user->save();
        }

        if (! $identity instanceof FirebaseIdentity) {
            return;
        }

        // A successful read means that the account exists and is enabled.
        $identity->forceFill([
            'email_verified_at' => $firebaseUser->emailVerified ? ($identity->email_verified_at ?? $identity->freshTimestamp()) : null,
            'synced_at' => $identity->freshTimestamp(),
            'tokens_valid_after' => $firebaseUser->tokensValidAfterTime,
            'invalidated_at' => null,
        ])->save();
    }
}
