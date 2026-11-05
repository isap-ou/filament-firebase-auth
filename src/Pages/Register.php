<?php

namespace Isapp\FilamentFirebaseAuth\Pages;

use Filament\Auth\Pages\Register as FilamentRegister;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Kreait\Firebase\Exception\Auth\WeakPassword;
use Kreait\Firebase\Exception\FirebaseException;
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
     *
     * The plugin deletes the Firebase account that it created here exactly once in two cases:
     * - The local step fails. The plugin deletes the account at once.
     * - The local step succeeds, but the open transaction of the page rolls back later (for example,
     *   an `afterRegister` hook throws). The plugin deletes the account after the rollback.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when Firebase refuses to create the account.
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $firebaseUser = $this->createFirebaseUser(
            (string) $data['email'],
            (string) ($this->data['password'] ?? ''),
            is_string($data['name'] ?? null) ? $data['name'] : null,
        );

        try {
            $user = $this->createLocalUser($firebaseUser);
        } catch (Throwable $exception) {
            $this->deleteFirebaseUser($firebaseUser->uid);

            throw $exception;
        }

        // Laravel attaches this callback to the last open transaction, on any connection. Here that is
        // Filament's page transaction (or an outer host transaction), because the transaction of the
        // local step has already committed. Without an open transaction, Laravel ignores the callback,
        // and the committed record keeps its Firebase account.
        DB::afterRollBack(fn () => $this->deleteFirebaseUser($firebaseUser->uid));

        return $user;
    }

    /**
     * The new account has an unverified email. Filament's verification email then goes out as usual.
     *
     * An email that already has a Firebase account gets the same error as a local duplicate.
     * So the page never tells whether an email has an account in a shared Firebase project.
     * A weak password gets a form error. Any other Firebase error gets a generic notification,
     * and the plugin logs only the exception class.
     */
    protected function createFirebaseUser(string $email, #[SensitiveParameter] string $password, ?string $name): UserRecord
    {
        try {
            return app(CreateFirebaseUser::class)->handle($email, $password, $name, verified: false);
        } catch (EmailExists) {
            throw ValidationException::withMessages($this->getEmailUniqueError());
        } catch (WeakPassword) {
            throw ValidationException::withMessages([
                'data.password' => __('filament-firebase-auth::register.weak_password'),
            ]);
        } catch (FirebaseException $exception) {
            logger()->error('The plugin could not create the Firebase account of a registration.', [
                'exception' => $exception::class,
            ]);

            Notification::make()
                ->title(__('filament-firebase-auth::register.failed'))
                ->danger()
                ->send();

            throw ValidationException::withMessages([]);
        }
    }

    /**
     * Builds the error of Filament's `unique` rule on the email field through Laravel's validator.
     * So the custom messages and attributes of the form and the `validation.custom` lines apply.
     *
     * @return array<string, string>
     */
    protected function getEmailUniqueError(): array
    {
        $field = $this->form->getFlatFields(withHidden: true)['email'] ?? null;
        $statePath = $field instanceof Field ? $field->getStatePath() : 'data.email';
        $parameters = [];

        foreach ($field instanceof Field ? $field->getValidationRules() : [] as $rule) {
            if ($rule instanceof Unique) {
                [, $parameters] = ValidationRuleParser::parse((string) $rule);

                break;
            }
        }

        $validator = Validator::make(
            ['data' => ['email' => data_get($this->data, 'email')]],
            [],
            $this->form->getValidationMessages(),
            $this->form->getValidationAttributes(),
        );

        $validator->addFailure($statePath, 'unique', $parameters);

        return [$statePath => (string) $validator->errors()->first($statePath)];
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
