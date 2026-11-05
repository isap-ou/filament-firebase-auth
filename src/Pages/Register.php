<?php

namespace Isapp\FilamentFirebaseAuth\Pages;

use Filament\Auth\Pages\Register as FilamentRegister;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\EmailExists;
use SensitiveParameter;
use Throwable;

/**
 * The registration page of a Firebase panel.
 * The host selects it with `->registration(Register::class)`.
 *
 * Only the save step changes. The form, the rate limits, the `Registered` event,
 * the verification email and the login stay Filament's.
 */
class Register extends FilamentRegister
{
    /**
     * Creates the Firebase account, then the local record and its link.
     *
     * Filament hashes the password only in the copy that `getState()` returns, so `$this->data`
     * still holds the clear password. The plugin gives it to Firebase and never stores the hash.
     * When the local step fails, the plugin deletes the Firebase account that it created here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the email already has a Firebase account.
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $firebaseUser = $this->createFirebaseUser(
            (string) $data['email'],
            (string) ($this->data['password'] ?? ''),
            is_string($data['name'] ?? null) ? $data['name'] : null,
        );

        try {
            return $this->createLocalUser($firebaseUser);
        } catch (Throwable $exception) {
            $this->deleteFirebaseUser($firebaseUser->uid);

            throw $exception;
        }
    }

    /**
     * The new account has an unverified email. Filament's verification email then goes out as usual.
     */
    protected function createFirebaseUser(string $email, #[SensitiveParameter] string $password, ?string $name): UserRecord
    {
        try {
            return app(CreateFirebaseUser::class)->handle($email, $password, $name, verified: false);
        } catch (EmailExists) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-firebase-auth::register.email_exists'),
            ]);
        }
    }

    /**
     * Creates the record and its link in one transaction on the connection of the host model.
     */
    protected function createLocalUser(UserRecord $firebaseUser): Model & Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $model */
        $model = $this->getUserModel();

        return (new $model)->getConnection()->transaction(function () use ($firebaseUser, $model): Model & Authenticatable {
            $user = app(CreatesLocalUsers::class)->handle($firebaseUser, $model, Filament::getCurrentOrDefaultPanel());

            app(LinkFirebaseIdentity::class)->handle($user, $firebaseUser);

            return $user;
        });
    }

    /**
     * A failed deletion never hides the local error. The plugin logs the UID and the exception class.
     */
    protected function deleteFirebaseUser(string $uid): void
    {
        try {
            app(DeleteFirebaseUser::class)->handle($uid);
        } catch (Throwable $exception) {
            logger()->error('The plugin could not delete the Firebase account of a failed registration.', [
                'uid' => $uid,
                'exception' => $exception::class,
            ]);
        }
    }
}
