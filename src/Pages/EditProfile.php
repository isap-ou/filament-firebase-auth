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
use Isapp\FilamentFirebaseAuth\Actions\Firebase\RevokeFirebaseTokens;
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
            ->visible(fn (Get $get): bool => filled($get('password')) || ($get('email') !== $this->getUser()->getAttributeValue('email')))
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
        $email = $this->getUser()->getAttributeValue('email');

        if ($uid === null || ! is_string($email) || ! is_string($password) || $password === '') {
            return __('validation.current_password');
        }

        try {
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
     * Firebase stores the new email and the new password. The local record never stores a password.
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

        $identity = $this->getFirebaseIdentity();

        if (! $identity instanceof FirebaseIdentity || ! $this->userHasFirebasePasswordProvider()) {
            // The form disables the email of a user without a password. A changed request must not change it either.
            unset($data['email']);

            return parent::handleRecordUpdate($record, $data);
        }

        $firebaseUser = $this->updateFirebaseUser($identity->firebase_uid, $record, $data);

        if ($firebaseUser instanceof UserRecord) {
            // Firebase revokes the tokens on a password or email change. The link stores the new time.
            app(SyncsLocalUsers::class)->handle($this->getUser(), $firebaseUser);
        }

        $record = parent::handleRecordUpdate($record, $data);

        if ($firebaseUser instanceof UserRecord) {
            app(RenewCurrentSession::class)->handle($this->getUser());
        }

        return $record;
    }

    /**
     * Returns the Firebase user after the last change, or null when nothing changed in Firebase.
     *
     * The email changes first: Firebase refuses an email more often than a password,
     * and then nothing has changed in Firebase.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when Firebase refuses the change.
     */
    protected function updateFirebaseUser(string $uid, Model $record, #[SensitiveParameter] array $data): ?UserRecord
    {
        $firebaseUser = null;
        $field = 'email';
        $email = $data['email'] ?? null;
        $password = $this->data['password'] ?? null;

        try {
            if (! Filament::hasEmailChangeVerification() && is_string($email) && $email !== $record->getAttributeValue('email')) {
                // Nobody proved the new email, so Firebase must not keep the verified flag.
                $firebaseUser = app(ChangeFirebaseEmail::class)->handle($uid, $email, verified: false);
            }

            if (is_string($password) && $password !== '') {
                $field = 'password';
                $firebaseUser = app(ChangeFirebasePassword::class)->handle($uid, $password);
            }
        } catch (EmailExists) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-firebase-auth::profile.messages.email_exists'),
            ]);
        } catch (FirebaseException $exception) {
            logger()->warning('Firebase refused a profile change.', [
                'uid' => $uid,
                'field' => $field,
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                "data.{$field}" => __('filament-firebase-auth::profile.messages.firebase_failed'),
            ]);
        }

        return $firebaseUser;
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
            ->action(function (): void {
                $user = $this->getUser();
                $identity = $this->getFirebaseIdentity();

                if (! $identity instanceof FirebaseIdentity) {
                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.no_link', success: false);

                    return;
                }

                try {
                    $firebaseUser = app(RevokeFirebaseTokens::class)->handle($identity->firebase_uid);
                } catch (FirebaseException $exception) {
                    logger()->warning('Firebase could not revoke the tokens.', [
                        'uid' => $identity->firebase_uid,
                        'exception' => $exception::class,
                    ]);

                    $this->sendFirebaseNotification('filament-firebase-auth::profile.messages.firebase_failed', success: false);

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

    protected function userHasFirebasePasswordProvider(): bool
    {
        $user = $this->getUser();

        return method_exists($user, 'hasFirebasePasswordProvider') && $user->hasFirebasePasswordProvider();
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
