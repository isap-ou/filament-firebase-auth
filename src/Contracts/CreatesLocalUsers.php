<?php

namespace Isapp\FilamentFirebaseAuth\Contracts;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Creates the local record for a Firebase user.
 *
 * A host app binds its own class to set roles, defaults or relations.
 */
interface CreatesLocalUsers
{
    /**
     * Returns a saved record. The caller creates the link after this call.
     *
     * @param  class-string<Model&Authenticatable>  $model
     */
    public function handle(UserRecord $firebaseUser, string $model, ?Panel $panel): Model & Authenticatable;
}
