<?php

namespace Isapp\FilamentFirebaseAuth\Concerns;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Contract\Auth;

/**
 * Connects a host model to its Firebase user through the link table.
 *
 * The host model implements `MustVerifyEmail` and `CanResetPassword`. It never stores a password.
 *
 * @property-read FirebaseIdentity|null $firebaseIdentity
 */
trait HasFirebaseIdentity
{
    use CanResetPassword;

    /**
     * The memoized answer of hasFirebasePasswordProvider() for this instance.
     */
    protected ?bool $firebasePasswordProvider = null;

    /**
     * Laravel calls this method when it boots the model.
     */
    protected static function bootHasFirebaseIdentity(): void
    {
        static::saving(function (self $model): void {
            // Filament's reset callback writes a hash to this attribute. The plugin never stores it.
            $name = $model->getAuthPasswordName();

            if (! $model->isDirty($name)) {
                return;
            }

            $attributes = $model->getAttributes();
            $original = $model->getRawOriginal();

            if (array_key_exists($name, $original)) {
                $attributes[$name] = $original[$name];
            } else {
                unset($attributes[$name]);
            }

            $model->setRawAttributes($attributes);
        });
    }

    /**
     * @return MorphOne<FirebaseIdentity, $this>
     */
    public function firebaseIdentity(): MorphOne
    {
        return $this->morphOne(FirebaseIdentity::class, 'authenticatable');
    }

    public function getFirebaseUid(): ?string
    {
        return $this->firebaseIdentity?->firebase_uid;
    }

    /**
     * Reads the local copy of the Firebase flag. It never calls Firebase.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->firebaseIdentity?->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->setFirebaseEmailVerified(true);
    }

    public function markEmailAsUnverified(): bool
    {
        return $this->setFirebaseEmailVerified(false);
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->getAttribute('email');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Returns true when the Firebase user can sign in with an email and a password.
     */
    public function hasFirebasePasswordProvider(): bool
    {
        if ($this->firebasePasswordProvider !== null) {
            return $this->firebasePasswordProvider;
        }

        $uid = $this->getFirebaseUid();

        if ($uid === null) {
            return false;
        }

        $this->firebasePasswordProvider = false;

        foreach (app(Auth::class)->getUser($uid)->providerData as $provider) {
            if ($provider->providerId === 'password') {
                $this->firebasePasswordProvider = true;
            }
        }

        return $this->firebasePasswordProvider;
    }

    /**
     * Returns the session fingerprint, not a password.
     *
     * Laravel's AuthenticateSession logs the user out when this value changes.
     * It skips users with an empty value, so the value is never empty. It reads the link only.
     */
    public function getAuthPassword(): string
    {
        $identity = $this->firebaseIdentity;

        return hash('sha256', implode('|', [
            $identity->firebase_uid ?? 'unlinked',
            $identity?->tokens_valid_after?->getTimestamp() ?? 0,
            $identity?->invalidated_at?->getTimestamp() ?? 0,
            $identity->session_salt ?? '',
        ]));
    }

    /**
     * Writes a new salt to the link. Other sessions of this record then log out.
     */
    public function rotateSessionSalt(): void
    {
        $this->firebaseIdentity?->update(['session_salt' => Str::random(64)]);
    }

    protected function setFirebaseEmailVerified(bool $verified): bool
    {
        $identity = $this->firebaseIdentity;

        if ($identity === null) {
            return false;
        }

        app(Auth::class)->updateUser($identity->firebase_uid, ['emailVerified' => $verified]);

        return $identity->forceFill([
            'email_verified_at' => $verified ? $identity->freshTimestamp() : null,
        ])->save();
    }
}
