<?php

namespace Isapp\FilamentFirebaseAuth\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DeleteUserAccount;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\RenewCurrentSession;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\ChangeFirebaseEmail;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\ChangeFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FetchFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\RevokeFirebaseTokens;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\SetFirebaseDisplayName;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\EmailExists;
use Kreait\Firebase\Exception\FirebaseException;
use SensitiveParameter;

/**
 * The profile page of a Firebase panel.
 * The host selects it with `->profile(EditProfile::class)`.
 *
 * The form is Filament's own form. Firebase checks the current password and stores the new
 * password and the new email. A user without the Firebase `password` provider (for example a
 * Google-only user) cannot change the password or the email here.
 */
class EditProfile extends FilamentEditProfile
{
    /**
     * The answer of `userHasFirebasePasswordProvider()` for this request. Livewire does not keep
     * protected properties between requests.
     */
    protected ?bool $firebasePasswordProvider = null;

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getFirebaseAccountContentComponent(),
            ]);
    }

    /**
     * The account actions. They are part of the content, because a simple page has no header actions.
     */
    protected function getFirebaseAccountContentComponent(): Component
    {
        return Section::make(__('filament-firebase-auth::profile.section.label'))
            ->compact()
            ->secondary()
            ->schema([
                Actions::make([
                    $this->logoutOtherSessionsAction(),
                    $this->logoutEverywhereAction(),
                    $this->deleteAccountAction(),
                ]),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        // The email of a user without a password belongs to the social provider.
        return parent::getEmailFormComponent()
            ->disabled(fn (): bool => ! $this->userHasFirebasePasswordProvider());
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->visible(fn (): bool => $this->userHasFirebasePasswordProvider());
    }

    /**
     * Filament's component with a Firebase password check in place of the `current_password` rule.
     * That rule compares a local hash, and the plugin stores none.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label(__('filament-panels::auth/pages/edit-profile.form.current_password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.current_password.validation_attribute'))
            ->belowContent(__('filament-panels::auth/pages/edit-profile.form.current_password.below_content'))
            ->password()
            ->autocomplete('current-password')
            ->rule($this->getFirebaseCurrentPasswordRule())
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (Get $get): bool => filled($get('password')) || (! $this->isSameEmail($get('email'), $this->getUser()->getAttributeValue('email'))))
            ->dehydrated(false);
    }

    /**
     * The current password field of the account actions. Only a user with a password sees it.
     */
    protected function getFirebaseCurrentPasswordActionComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label(__('filament-panels::auth/pages/edit-profile.form.current_password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.current_password.validation_attribute'))
            ->password()
            ->autocomplete('current-password')
            ->rule($this->getFirebaseCurrentPasswordRule())
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (): bool => $this->userHasFirebasePasswordProvider())
            ->dehydrated(false);
    }

    /**
     * Filament evaluates the outer closure. Laravel's validator calls the inner closure.
     */
    protected function getFirebaseCurrentPasswordRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $message = $this->checkFirebaseCurrentPassword($value);

            if ($message !== null) {
                $fail($message);
            }
        };
    }

    /**
     * Returns null when Firebase accepts the password of the linked user, or else the error message.
     */
    protected function checkFirebaseCurrentPassword(mixed $password): ?string
    {
        $uid = $this->getFirebaseIdentity()?->firebase_uid;

        if ($uid === null || ! is_string($password) || $password === '') {
            return __('validation.current_password');
        }

        try {
            // The password belongs to the Firebase email of the linked user. The local email can differ.
            $email = app(FindFirebaseUser::class)->handle($uid)?->email;

            if ($email === null) {
                return __('validation.current_password');
            }

            $firebaseUser = app(CheckFirebasePassword::class)->handle($email, $password);
        } catch (FirebaseException $exception) {
            logger()->warning('Firebase could not check the current password.', [
                'uid' => $uid,
                'exception' => $exception::class,
            ]);

            return __('filament-firebase-auth::profile.messages.firebase_unavailable');
        }

        // Only the password of the linked Firebase user counts.
        return $firebaseUser?->uid === $uid ? null : __('validation.current_password');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        // Filament puts `$data['password']` into the session after the save (`EditProfile::save()`).
        // That value is a hash of the new password, not the session fingerprint of the plugin.
        // With it, Laravel's AuthenticateSession would log this session out on the next request.
        unset($data['password']);

        return parent::mutateFormDataBeforeSave($data);
    }

    /**
     * Firebase stores the new name, the new email and the new password. The local record never
     * stores a password. Firebase is the source of truth for the name, so the sync on the next
     * sign-in keeps the new name.
     *
     * Filament hashes only the copy of the state that `getState()` returns, so the clear password
     * comes from `$this->data`. With Filament's email-change verification, Filament sends the
     * verification mail, and `EmailChangeVerificationRequest` changes the Firebase email later.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        unset($data['password']);

        // Firebase stores emails in lower case. A change of case only is no change.
        if ($this->isSameEmail($data['email'] ?? null, $record->getAttributeValue('email'))) {
            unset($data['email']);
        }

        $identity = $this->getFirebaseIdentity();

        if (! $identity instanceof FirebaseIdentity) {
            unset($data['email']);

            return parent::handleRecordUpdate($record, $data);
        }

        // The form hides the password and disables the email of a user without a password.
        // A changed request must not change them either.
        $changesCredentials = $this->userHasFirebasePasswordProvider();

        if (! $changesCredentials) {
            unset($data['email']);
        }

        $firebaseUser = $this->updateFirebaseUser($identity, $record, $data, $changesCredentials);

        if (! $firebaseUser instanceof UserRecord) {
            return parent::handleRecordUpdate($record, $data);
        }

        $user = $this->getUser();
        $fingerprint = $user->getAuthPassword();

        // Firebase revokes the tokens on a password or email change. The link stores the new time.
        app(SyncsLocalUsers::class)->handle($user, $firebaseUser);

        $record = parent::handleRecordUpdate($record, $data);

        if ($user->getAuthPassword() !== $fingerprint) {
            app(RenewCurrentSession::class)->handle($user);
        }

        return $record;
    }

    /**
     * Returns the Firebase user after the last change, or null when nothing changed in Firebase.
     *
     * The order is email, name, password. Firebase refuses an email more often than the rest.
     * When a later call fails after the email changed, the email changes back in Firebase.
     * A new password cannot change back, so it goes last.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when Firebase refuses the change.
     */
    protected function updateFirebaseUser(FirebaseIdentity $identity, Model $record, #[SensitiveParameter] array $data, bool $changesCredentials): ?UserRecord
    {
        $uid = $identity->firebase_uid;
        $firebaseUser = null;
        $oldEmail = $record->getAttributeValue('email');
        $email = $changesCredentials ? ($data['email'] ?? null) : null;
        $name = $data['name'] ?? null;
        $password = $changesCredentials ? ($this->data['password'] ?? null) : null;
        $emailChanged = false;

        if (! Filament::hasEmailChangeVerification() && is_string($email)) {
            try {
                // Nobody proved the new email, so Firebase must not keep the verified flag.
                $firebaseUser = app(ChangeFirebaseEmail::class)->handle($uid, $email, verified: false);
            } catch (FirebaseException $exception) {
                throw $this->firebaseValidationException($exception, $uid, 'email');
            }

            $emailChanged = true;
        }

        $field = 'name';

        try {
            if (is_string($name) && $name !== '' && $name !== $record->getAttributeValue('name')) {
                $firebaseUser = app(SetFirebaseDisplayName::class)->handle($uid, $name);
            }

            if (is_string($password) && $password !== '') {
                $field = 'password';
                $firebaseUser = app(ChangeFirebasePassword::class)->handle($uid, $password);
            }
        } catch (FirebaseException $exception) {
            if ($emailChanged && is_string($oldEmail)) {
                $this->restoreFirebaseEmail($identity, $oldEmail);
            }

            throw $this->firebaseValidationException($exception, $uid, $field);
        }

        return $firebaseUser;
    }

    protected function firebaseValidationException(FirebaseException $exception, string $uid, string $field): ValidationException
    {
        if ($exception instanceof EmailExists) {
            return ValidationException::withMessages([
                'data.email' => __('filament-firebase-auth::profile.messages.email_exists'),
            ]);
        }

        logger()->warning('Firebase refused a profile change.', [
            'uid' => $uid,
            'field' => $field,
            'exception' => $exception::class,
        ]);

        return ValidationException::withMessages([
            "data.{$field}" => __('filament-firebase-auth::profile.messages.firebase_failed'),
        ]);
    }

    /**
     * Gives Firebase the old email and the old verified flag of the link again.
     */
    protected function restoreFirebaseEmail(FirebaseIdentity $identity, string $oldEmail): void
    {
        try {
            app(ChangeFirebaseEmail::class)->handle($identity->firebase_uid, $oldEmail, verified: $identity->email_verified_at !== null);
        } catch (FirebaseException $exception) {
            logger()->error('Firebase could not restore the email after a failed profile change.', [
                'uid' => $identity->firebase_uid,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Rotates the session salt of the link. Other sessions of this record log out on their next request.
     */
    protected function logoutOtherSessionsAction(): Action
    {
        return Action::make('logoutOtherSessions')
            ->label(__('filament-firebase-auth::profile.actions.logout_other_sessions.label'))
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('filament-firebase-auth::profile.actions.logout_other_sessions.modal_description'))
            ->schema([
                $this->getFirebaseCurrentPasswordActionComponent(),
            ])
            ->rateLimit(5)
            ->action(function (): void {
                $user = $this->getUser();

                // `rotateSessionSalt()` does nothing without a link. Do not report a success then.
                if (! $this->getFirebaseIdentity() instanceof FirebaseIdentity || ! method_exists($user, 'rotateSessionSalt')) {
                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.no_link', success: false);

                    return;
                }

                $user->rotateSessionSalt();

                app(RenewCurrentSession::class)->handle($user);

                $this->sendFirebaseNotification('filament-firebase-auth::profile.actions.logout_other_sessions.notifications.done');
            });
    }

    /**
     * Revokes the Firebase refresh tokens. This also signs the person out of other apps
     * on the same Firebase project, so the `global_logout` config key must allow it.
     */
    protected function logoutEverywhereAction(): Action
    {
        return Action::make('logoutEverywhere')
            ->label(__('filament-firebase-auth::profile.actions.logout_everywhere.label'))
            ->color('gray')
            ->visible(fn (): bool => config()->boolean('filament-firebase-auth.global_logout'))
            ->requiresConfirmation()
            ->modalDescription(__('filament-firebase-auth::profile.actions.logout_everywhere.modal_description'))
            ->schema([
                $this->getFirebaseCurrentPasswordActionComponent(),
            ])
            ->rateLimit(5)
            ->action(function (): void {
                $user = $this->getUser();
                $identity = $this->getFirebaseIdentity();

                if (! $identity instanceof FirebaseIdentity) {
                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.no_link', success: false);

                    return;
                }

                try {
                    app(RevokeFirebaseTokens::class)->handle($identity->firebase_uid);

                    // `FindFirebaseUser` can hold the record from before the revocation.
                    $firebaseUser = app(FetchFirebaseUser::class)->handle($identity->firebase_uid);
                } catch (FirebaseException $exception) {
                    logger()->warning('Firebase could not revoke the tokens.', [
                        'uid' => $identity->firebase_uid,
                        'exception' => $exception::class,
                    ]);

                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.firebase_failed', success: false);

                    return;
                }

                if (! $firebaseUser instanceof UserRecord) {
                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.no_link', success: false);

                    return;
                }

                // The new `tokens_valid_after` changes the fingerprint, so all sessions of this record log out.
                app(SyncsLocalUsers::class)->handle($user, $firebaseUser);

                app(RenewCurrentSession::class)->handle($user);

                $this->sendFirebaseNotification('filament-firebase-auth::profile.actions.logout_everywhere.notifications.done');
            });
    }

    /**
     * Deletes the local record and its link, runs the deletion policy and logs out.
     */
    protected function deleteAccountAction(): Action
    {
        return Action::make('deleteAccount')
            ->label(__('filament-firebase-auth::profile.actions.delete_account.label'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('filament-firebase-auth::profile.actions.delete_account.modal_description'))
            ->schema([
                $this->getFirebaseCurrentPasswordActionComponent(),
            ])
            ->rateLimit(5)
            ->action(function (): void {
                $user = $this->getUser();

                // Log out before the deletion. `logout()` saves a new remember token on the record,
                // and a save after the deletion would insert the record again.
                Filament::auth()->logout();

                session()->invalidate();
                session()->regenerateToken();

                app(DeleteUserAccount::class)->handle($user);

                $this->redirect((string) Filament::getLoginUrl());
            });
    }

    /**
     * When Firebase cannot answer, the page shows the password fields. The Firebase check of the
     * current password then fails with a clear message, so nothing changes. The page never hides
     * the fields because of an outage.
     */
    protected function userHasFirebasePasswordProvider(): bool
    {
        if ($this->firebasePasswordProvider !== null) {
            return $this->firebasePasswordProvider;
        }

        $user = $this->getUser();

        if (! method_exists($user, 'hasFirebasePasswordProvider')) {
            return $this->firebasePasswordProvider = false;
        }

        try {
            return $this->firebasePasswordProvider = $user->hasFirebasePasswordProvider();
        } catch (FirebaseException $exception) {
            logger()->warning('Firebase could not tell if the user has a password.', [
                'exception' => $exception::class,
            ]);

            return $this->firebasePasswordProvider = true;
        }
    }

    protected function isSameEmail(mixed $email, mixed $otherEmail): bool
    {
        if (! is_string($email) || ! is_string($otherEmail)) {
            return $email === $otherEmail;
        }

        return mb_strtolower($email) === mb_strtolower($otherEmail);
    }

    protected function getFirebaseIdentity(): ?FirebaseIdentity
    {
        $identity = $this->getUser()->getRelationValue('firebaseIdentity');

        return $identity instanceof FirebaseIdentity ? $identity : null;
    }

    protected function sendFirebaseNotification(string $titleKey, bool $success = true): void
    {
        FilamentNotification::make()
            ->status($success ? 'success' : 'danger')
            ->title(__($titleKey))
            ->send();
    }
}
