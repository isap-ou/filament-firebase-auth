<?php

namespace Isapp\FilamentFirebaseAuth\Http\Requests;

use Filament\Auth\Http\Requests\EmailChangeVerificationRequest as FilamentEmailChangeVerificationRequest;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\RenewCurrentSession;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\ChangeFirebaseEmail;
use Isapp\FilamentFirebaseAuth\Concerns\HasFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Exception\FirebaseException;

/**
 * Changes the Firebase email when the person opens the link of Filament's email-change verification.
 *
 * The service provider binds Filament's request class to this class, so Filament's
 * `EmailChangeVerificationController` receives it. A record without `HasFirebaseIdentity`,
 * or without a link (for example on another panel that shares the model), gets Filament's behaviour.
 */
class EmailChangeVerificationRequest extends FilamentEmailChangeVerificationRequest
{
    /**
     * Filament's own `fulfill()` does not run for a linked record. It marks the email as verified
     * through the trait, which sends the flag to Firebase while Firebase still has the old email.
     *
     * The order:
     * 1. Refuse an email that another local record uses. Firebase does not change then.
     * 2. Use up the one-time signature, as Filament does. After that, the block link of the old
     *    email cannot race with the Firebase change, and a second click finds no signature.
     * 3. Send the new email and the verified flag to Firebase in one call. When Firebase refuses,
     *    the signature goes back to the cache, nothing changes, and the method returns false.
     * 4. Save the email on the record and sync the link. The sync sets `email_verified_at`
     *    from the Firebase answer, so no second Firebase call is needed.
     */
    public function fulfill(): bool
    {
        $user = $this->user();

        if (! $user instanceof Model || ! in_array(HasFirebaseIdentity::class, class_uses_recursive($user), true)) {
            return parent::fulfill();
        }

        $identity = $user->getRelationValue('firebaseIdentity');

        if (! $identity instanceof FirebaseIdentity) {
            return parent::fulfill();
        }

        $newEmail = (string) decrypt($this->route('email'));

        $isEmailTaken = $user::query()
            ->whereKeyNot($user->getKey())
            ->where('email', $newEmail)
            ->useWritePdo()
            ->exists();

        if ($isEmailTaken) {
            return false;
        }

        $consumedKeys = $this->consumeSignature();

        if ($consumedKeys === null) {
            return false;
        }

        try {
            $firebaseUser = app(ChangeFirebaseEmail::class)->handle($identity->firebase_uid, $newEmail, verified: true);
        } catch (FirebaseException $exception) {
            logger()->warning('Firebase refused the email change.', [
                'uid' => $identity->firebase_uid,
                'exception' => $exception::class,
            ]);

            $this->restoreSignature($consumedKeys);

            return false;
        }

        $user->getConnection()->transaction(function () use ($user, $newEmail, $firebaseUser): void {
            $user->update(['email' => $newEmail]);

            // Firebase revokes the tokens of a user whose email changes. The link stores the new time.
            app(SyncsLocalUsers::class)->handle($user, $firebaseUser);
        });

        app(RenewCurrentSession::class)->handle($user);

        return true;
    }

    /**
     * Removes the signature from the cache with the same keys as Filament's `fulfill()`.
     *
     * @return list<string>|null The removed keys, or null when the signature was not pending
     *                           or could not be removed.
     */
    protected function consumeSignature(): ?array
    {
        $signature = $this->query('signature');
        $cache = cache();
        $wasPending = false;
        $wasRemoved = true;
        $consumedKeys = [];

        foreach (["filament:auth:email_change:verification:{$signature}", $signature] as $cacheKey) {
            if (! is_string($cacheKey) || ! $cache->has($cacheKey)) {
                continue;
            }

            $wasPending = true;

            if ((! $cache->forget($cacheKey)) && ($cache->get($cacheKey) !== null)) {
                $wasRemoved = false;

                continue;
            }

            $consumedKeys[] = $cacheKey;
        }

        if (! $wasPending || ! $wasRemoved) {
            $this->restoreSignature($consumedKeys);

            return null;
        }

        return $consumedKeys;
    }

    /**
     * Puts the signature back, with the lifetime that Filament gives it (`EditProfile::sendEmailChangeVerification()`).
     * The signed URL has its own expiry, so the link never lives longer than Filament allows.
     *
     * @param  list<string>  $cacheKeys
     */
    protected function restoreSignature(array $cacheKeys): void
    {
        foreach ($cacheKeys as $cacheKey) {
            cache()->put($cacheKey, true, ttl: now()->addHour());
        }
    }
}
