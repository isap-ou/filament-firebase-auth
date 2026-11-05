<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Creates or refreshes the link between a saved record and a Firebase user.
 */
class LinkFirebaseIdentity
{
    public function __construct(
        protected EnsureFirebaseIdentityLinkable $ensureFirebaseIdentityLinkable,
        protected RefreshFirebaseIdentity $refreshFirebaseIdentity,
    ) {}

    /**
     * Refuses a disabled Firebase user, an unsaved record, a record linked to another Firebase user,
     * and a Firebase user that is linked to another record of the same model.
     *
     * @throws FirebaseIdentityNotLinkable
     */
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): FirebaseIdentity
    {
        $identity = $this->ensureFirebaseIdentityLinkable->handle($user, $firebaseUser);

        // The morph columns are not fillable.
        $identity ??= (new FirebaseIdentity)->forceFill([
            'firebase_uid' => $firebaseUser->uid,
            'authenticatable_type' => $user->getMorphClass(),
            'authenticatable_id' => $user->getKey(),
        ]);

        $this->refreshFirebaseIdentity->handle($identity, $firebaseUser);

        $user->setRelation('firebaseIdentity', $identity);

        return $identity;
    }
}
