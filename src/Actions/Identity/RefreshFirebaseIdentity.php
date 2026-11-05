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
    /**
     * Pass `$localEmail` when the record can hold another email than Firebase, for example before
     * its save. A verified Firebase email then raises `email_verified_at` only when the two emails
     * are the same, compared without case. An unverified Firebase email always clears the flag.
     */
    public function handle(FirebaseIdentity $identity, UserRecord $firebaseUser, ?string $localEmail = null): void
    {
        $now = $identity->freshTimestamp();

        $provesLocalEmail = $localEmail === null
            || ($firebaseUser->email !== null && mb_strtolower($firebaseUser->email) === mb_strtolower($localEmail));

        $identity->forceFill([
            'email_verified_at' => match (true) {
                ! $firebaseUser->emailVerified => null,
                $provesLocalEmail => $identity->email_verified_at ?? $now,
                // The Firebase email is verified, but it is not the local email. Keep the earlier value.
                default => $identity->email_verified_at,
            },
            'synced_at' => $now,
            'tokens_valid_after' => $firebaseUser->tokensValidAfterTime,
            // Firebase returns disabled users too. Only an enabled account clears the mark.
            'invalidated_at' => $firebaseUser->disabled ? ($identity->invalidated_at ?? $now) : null,
        ])->save();
    }
}
