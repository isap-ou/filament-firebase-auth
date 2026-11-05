<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

/**
 * Creates or refreshes the link between a saved record and a Firebase user.
 */
class LinkFirebaseIdentity
{
    public function __construct(protected RefreshFirebaseIdentity $refreshFirebaseIdentity) {}

    /**
     * Refuses a disabled Firebase user, an unsaved record, a record linked to another Firebase user,
     * and a Firebase user that is linked to another record of the same model.
     */
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): FirebaseIdentity
    {
        if ($firebaseUser->disabled) {
            throw new LogicException('The Firebase user is disabled.');
        }

        if (! $user->exists) {
            throw new LogicException('Only a saved record can be linked.');
        }

        $morphType = $user->getMorphClass();

        $identity = FirebaseIdentity::query()
            ->where('authenticatable_type', $morphType)
            ->where('authenticatable_id', $user->getKey())
            ->first();

        if ($identity !== null && $identity->firebase_uid !== $firebaseUser->uid) {
            throw new LogicException('The record is linked to another Firebase user.');
        }

        if ($identity === null && FirebaseIdentity::query()->forFirebaseUid($firebaseUser->uid, $morphType)->exists()) {
            throw new LogicException('The Firebase user is linked to another record of this model.');
        }

        // The morph columns are not fillable.
        $identity ??= (new FirebaseIdentity)->forceFill([
            'firebase_uid' => $firebaseUser->uid,
            'authenticatable_type' => $morphType,
            'authenticatable_id' => $user->getKey(),
        ]);

        $this->refreshFirebaseIdentity->handle($identity, $firebaseUser);

        $user->setRelation('firebaseIdentity', $identity);

        return $identity;
    }
}
