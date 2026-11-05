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
    /**
     * Refuses a record that is linked to another Firebase user.
     */
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): FirebaseIdentity
    {
        $identity = FirebaseIdentity::query()
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getKey())
            ->first();

        if ($identity !== null && $identity->firebase_uid !== $firebaseUser->uid) {
            throw new LogicException('The record is linked to another Firebase user.');
        }

        // The morph columns are not fillable.
        $identity ??= (new FirebaseIdentity)->forceFill([
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->getKey(),
        ]);

        $identity->forceFill([
            'firebase_uid' => $firebaseUser->uid,
            'email_verified_at' => $firebaseUser->emailVerified ? ($identity->email_verified_at ?? $identity->freshTimestamp()) : null,
            'synced_at' => $identity->freshTimestamp(),
            'tokens_valid_after' => $firebaseUser->tokensValidAfterTime,
            'invalidated_at' => null,
        ])->save();

        $user->setRelation('firebaseIdentity', $identity);

        return $identity;
    }
}
