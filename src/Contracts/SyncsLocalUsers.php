<?php

namespace Isapp\FilamentFirebaseAuth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Copies Firebase user data to a linked local record.
 *
 * The plugin calls it on each sign-in and on each session check.
 * A host app that binds its own class must also update the link columns, as the default does:
 * `email_verified_at`, `synced_at`, `tokens_valid_after` and `invalidated_at`.
 * Without these updates, session invalidation breaks.
 */
interface SyncsLocalUsers
{
    public function handle(Model & Authenticatable $user, UserRecord $firebaseUser): void;
}
