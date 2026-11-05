<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Data\ResolvedUser;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;
use Isapp\FilamentFirebaseAuth\Exceptions\FirebaseIdentityNotLinkable;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Finds the local record of a Firebase user for a panel model. It never writes.
 *
 * `PersistResolvedUser` saves the answer after the credentials are checked.
 */
class ResolveLocalUser
{
    public function __construct(protected EnsureFirebaseIdentityLinkable $ensureFirebaseIdentityLinkable) {}

    /**
     * Applies the rules in this order:
     *
     * 1. A link exists for this UID and model: use its record.
     * 2. No link, but a record has the same email: use it when Firebase verified the email and
     *    the link rules allow it.
     * 3. No link, no record, and the panel creates records on sign-in: offer an unsaved record.
     * 4. Otherwise: block.
     *
     * A disabled Firebase user is always blocked.
     *
     * @param  class-string<Model&Authenticatable>  $model
     */
    public function handle(UserRecord $firebaseUser, string $model, bool $createOnSignIn): ResolvedUser
    {
        if ($firebaseUser->disabled) {
            return $this->blocked();
        }

        $instance = new $model;

        $identity = FirebaseIdentity::query()
            ->forFirebaseUid($firebaseUser->uid, $instance->getMorphClass())
            ->first();

        if ($identity !== null) {
            return $this->linked($instance, $identity);
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $firebaseUser->email === null
            ? null
            : $instance->newQuery()->where('email', $firebaseUser->email)->first();

        if ($user !== null) {
            return $this->linkedByEmail($user, $firebaseUser);
        }

        if (! $createOnSignIn) {
            return $this->blocked();
        }

        if ($firebaseUser->email !== null) {
            $instance->setAttribute('email', $firebaseUser->email);
        }

        return new ResolvedUser(Resolution::New, $instance);
    }

    /**
     * A link without a visible record (for example a soft deleted record) blocks the sign-in.
     */
    protected function linked(Model & Authenticatable $instance, FirebaseIdentity $identity): ResolvedUser
    {
        /** @var (Model&Authenticatable)|null $user */
        $user = $instance->newQuery()->whereKey($identity->authenticatable_id)->first();

        if ($user === null) {
            return $this->blocked();
        }

        $user->setRelation('firebaseIdentity', $identity);

        return new ResolvedUser(Resolution::Linked, $user);
    }

    /**
     * Anyone can create an unverified Firebase account with another person's email,
     * so only a verified email links the record.
     */
    protected function linkedByEmail(Model & Authenticatable $user, UserRecord $firebaseUser): ResolvedUser
    {
        if (! $firebaseUser->emailVerified) {
            return $this->blocked();
        }

        try {
            $this->ensureFirebaseIdentityLinkable->handle($user, $firebaseUser);
        } catch (FirebaseIdentityNotLinkable) {
            return $this->blocked();
        }

        return new ResolvedUser(Resolution::LinkedByEmail, $user);
    }

    protected function blocked(): ResolvedUser
    {
        return new ResolvedUser(Resolution::Blocked, null);
    }
}
