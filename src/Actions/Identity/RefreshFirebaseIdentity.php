<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;

/**
 * Copies the Firebase state to the link columns and saves the link.
 *
 * This is the one place that writes `email_verified_at`, `synced_at` and `tokens_valid_after`.
 * Two classes write `invalidated_at`: this class, and `Jobs\SyncFirebaseIdentity` when Firebase
 * has no user for the link. Only `tokens_valid_after` and `invalidated_at` are part of the session
 * fingerprint (`HasFirebaseIdentity::getAuthPassword()`). A change of one of them changes the
 * fingerprint. `email_verified_at` and `synced_at` do not change the fingerprint.
 */
class RefreshFirebaseIdentity
{
    public function handle(FirebaseIdentity $identity, UserRecord $firebaseUser): void
    {
        $now = $identity->freshTimestamp();

        $identity->forceFill([
            'email_verified_at' => $firebaseUser->emailVerified ? ($identity->email_verified_at ?? $now) : null,
            'synced_at' => $now,
            'tokens_valid_after' => $firebaseUser->tokensValidAfterTime,
            // Firebase returns disabled users too. Only an enabled account clears the mark.
            'invalidated_at' => $firebaseUser->disabled ? ($identity->invalidated_at ?? $now) : null,
        ])->save();
    }
}
