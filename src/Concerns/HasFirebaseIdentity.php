<?php

namespace Isapp\FilamentFirebaseAuth\Concerns;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DeleteFirebaseUserIfAllowed;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DetectPasswordProvider;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\SetFirebaseEmailVerified;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\VerifyFirebaseEmail;
use Isapp\FilamentFirebaseAuth\Enums\DeleteAccounts;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Throwable;

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

        static::deleted(function (self $model): void {
            // A soft delete keeps the link. `SoftDeletes::forceDelete()` fires `deleted` too.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->deleteFirebaseIdentity();
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
     *
     * A missing Firebase user has no password provider. Other Firebase exceptions go to the caller
     * and are not remembered.
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

        $firebaseUser = app(FindFirebaseUser::class)->handle($uid);
        $hasPasswordProvider = $firebaseUser !== null && app(DetectPasswordProvider::class)->handle($firebaseUser);

        return $this->firebasePasswordProvider = $hasPasswordProvider;
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

    /**
     * Deletes the link of a deleted record, then runs the deletion policy for its Firebase user.
     *
     * In `never` mode the plugin does not call Firebase. An unknown `delete_accounts` value throws.
     * The Firebase part runs after the open transaction commits, so a rollback never loses the
     * Firebase account. A failure in the Firebase part never stops the deletion of the local record.
     * The plugin logs the UID and the exception class, and goes on.
     */
    protected function deleteFirebaseIdentity(): void
    {
        $identity = $this->firebaseIdentity()->first();

        if ($identity === null) {
            return;
        }

        $uid = $identity->firebase_uid;

        $identity->delete();
        $this->unsetRelation('firebaseIdentity');

        if (DeleteAccounts::from(config()->string('filament-firebase-auth.delete_accounts')) === DeleteAccounts::Never) {
            return;
        }

        // Without an open transaction, Laravel runs the callback at once.
        $this->getConnection()->afterCommit(function () use ($uid): void {
            try {
                $firebaseUser = app(FindFirebaseUser::class)->handle($uid);

                if ($firebaseUser !== null) {
                    app(DeleteFirebaseUserIfAllowed::class)->handle($firebaseUser, DeletionReason::RecordDeleted);
                }
            } catch (Throwable $exception) {
                logger()->warning('The Firebase part of a record deletion failed.', [
                    'uid' => $uid,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    protected function setFirebaseEmailVerified(bool $verified): bool
    {
        $identity = $this->firebaseIdentity;

        if ($identity === null) {
            return false;
        }

        if ($verified) {
            // The verification link proves the local email only, never the current Firebase email.
            if (! app(VerifyFirebaseEmail::class)->handle($identity->firebase_uid, $this->getEmailForVerification())) {
                return false;
            }
        } else {
            app(SetFirebaseEmailVerified::class)->handle($identity->firebase_uid, false);
        }

        return $identity->forceFill([
            'email_verified_at' => $verified ? $identity->freshTimestamp() : null,
        ])->save();
    }
}
