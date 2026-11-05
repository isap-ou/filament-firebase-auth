<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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
     * A disabled Firebase user is always blocked. A Firebase user without an email is blocked unless a link exists.
     *
     * `$constraints` adds the host conditions of the user provider (extra credentials and `withQuery()`),
     * as Laravel's eloquent driver does. A linked record or a record with the same email that fails them
     * blocks the sign-in. They never apply to a new record.
     *
     * @param  class-string<Model&Authenticatable>  $model
     * @param  (Closure(Builder<Model>): mixed)|null  $constraints
     */
    public function handle(UserRecord $firebaseUser, string $model, bool $createOnSignIn, ?Closure $constraints = null): ResolvedUser
    {
        if ($firebaseUser->disabled) {
            return $this->blocked();
        }

        $instance = new $model;

        $identity = FirebaseIdentity::query()
            ->forFirebaseUid($firebaseUser->uid, $instance->getMorphClass())
            ->first();

        if ($identity !== null) {
            return $this->linked($instance, $identity, $constraints);
        }

        // A record needs an email. Some providers (for example Twitter without the email permission) give none.
        if ($firebaseUser->email === null) {
            return $this->blocked();
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $instance->newQuery()->where('email', $firebaseUser->email)->first();

        if ($user !== null) {
            return $this->meetsConstraints($user, $constraints) ? $this->linkedByEmail($user, $firebaseUser) : $this->blocked();
        }

        if (! $createOnSignIn) {
            return $this->blocked();
        }

        $instance->setAttribute('email', $firebaseUser->email);

        return new ResolvedUser(Resolution::New, $instance);
    }

    /**
     * A link without a visible record (for example a soft deleted record) blocks the sign-in.
     */
    protected function linked(Model & Authenticatable $instance, FirebaseIdentity $identity, ?Closure $constraints): ResolvedUser
    {
        $query = $instance->newQuery()->whereKey($identity->authenticatable_id);

        if ($constraints instanceof Closure) {
            $constraints($query);
        }

        /** @var (Model&Authenticatable)|null $user */
        $user = $query->first();

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

    protected function meetsConstraints(Model & Authenticatable $user, ?Closure $constraints): bool
    {
        if (! $constraints instanceof Closure) {
            return true;
        }

        $query = $user->newQuery()->whereKey($user->getKey());
        $constraints($query);

        return $query->exists();
    }

    protected function blocked(): ResolvedUser
    {
        return new ResolvedUser(Resolution::Blocked, null);
    }
}
