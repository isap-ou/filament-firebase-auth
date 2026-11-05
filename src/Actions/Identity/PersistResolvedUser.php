<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Data\ResolvedUser;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;
use Kreait\Firebase\Auth\UserRecord;
use LogicException;

/**
 * Saves the answer of `ResolveLocalUser` in one database transaction.
 *
 * Call it only after Firebase proved the credentials.
 */
class PersistResolvedUser
{
    public function __construct(
        protected CreatesLocalUsers $createsLocalUsers,
        protected LinkFirebaseIdentity $linkFirebaseIdentity,
        protected SyncsLocalUsers $syncsLocalUsers,
    ) {}

    /**
     * `New` creates the record and its link. `LinkedByEmail` creates the link. Then every
     * resolution syncs the record. A blocked resolution throws.
     */
    public function handle(ResolvedUser $resolved, UserRecord $firebaseUser, ?Panel $panel): Model & Authenticatable
    {
        $user = $resolved->user;

        if ($resolved->resolution === Resolution::Blocked || ! $user instanceof Model) {
            throw new LogicException('A blocked sign-in has no record to save.');
        }

        // The transaction runs on the connection of the host model, which writes the record.
        return $user->getConnection()->transaction(function () use ($resolved, $user, $firebaseUser, $panel): Model & Authenticatable {
            if ($resolved->resolution === Resolution::New) {
                $user = $this->createsLocalUsers->handle($firebaseUser, $user::class, $panel);
            }

            if ($resolved->resolution !== Resolution::Linked) {
                $this->linkFirebaseIdentity->handle($user, $firebaseUser);
            }

            $this->syncsLocalUsers->handle($user, $firebaseUser);

            return $user;
        });
    }
}
