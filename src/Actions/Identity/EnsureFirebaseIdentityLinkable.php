<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Checks the link rules between a saved record and a Firebase user. It never writes.
 */
class EnsureFirebaseIdentityLinkable
{
    /**
     * Refuses a disabled Firebase user, an unsaved record, a record linked to another Firebase user,
     * and a Firebase user that is linked to another record of the same model.
     *
     * Returns the existing link of the record, or null when the record has no link.
     *
     * @throws FirebaseIdentityNotLinkable
     */
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): ?FirebaseIdentity
    {
        if ($firebaseUser->disabled) {
            throw new FirebaseIdentityNotLinkable('The Firebase user is disabled.');
        }

        if (! $user->exists) {
            throw new FirebaseIdentityNotLinkable('Only a saved record can be linked.');
        }

        $morphType = $user->getMorphClass();

        $identity = FirebaseIdentity::query()
            ->where('authenticatable_type', $morphType)
            ->where('authenticatable_id', $user->getKey())
            ->first();

        if ($identity !== null && $identity->firebase_uid !== $firebaseUser->uid) {
            throw new FirebaseIdentityNotLinkable('The record is linked to another Firebase user.');
        }

        if ($identity === null && FirebaseIdentity::query()->forFirebaseUid($firebaseUser->uid, $morphType)->exists()) {
            throw new FirebaseIdentityNotLinkable('The Firebase user is linked to another record of this model.');
        }

        return $identity;
    }
}
