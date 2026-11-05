<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Identity;

use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DetectPasswordProvider;
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
 *
 * It also writes a new `session_salt` when the email becomes verified, or when the Firebase user loses
 * the password provider. A person can create an account with an email that they do not own. When the
 * owner later proves the email (Firebase then drops the unproven password), older sessions of the record
 * must end. A sign-in persists the link before it logs in, so the current sign-in stays.
 */
class RefreshFirebaseIdentity
{
    /**
     * Pass `$localEmail` when the record can hold another email than Firebase, for example before
     * its save. A verified Firebase email then raises `email_verified_at` only when the two emails
     * are the same, compared without case. An unverified Firebase email always clears the flag.
     */
    public function __construct(protected DetectPasswordProvider $detectPasswordProvider) {}

    public function handle(FirebaseIdentity $identity, UserRecord $firebaseUser, ?string $localEmail = null): void
    {
        $now = $identity->freshTimestamp();
        $wasVerified = $identity->email_verified_at !== null;
        $hadPasswordProvider = $identity->has_password_provider;
        $hasPasswordProvider = $this->detectPasswordProvider->handle($firebaseUser);

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
            'has_password_provider' => $hasPasswordProvider,
        ]);

        // A null value means that the plugin never read the providers, so nothing disappeared.
        if ((! $wasVerified && $identity->email_verified_at !== null) || ($hadPasswordProvider === true && ! $hasPasswordProvider)) {
            $identity->forceFill(['session_salt' => Str::random(64)]);
        }

        $identity->save();
    }
}
