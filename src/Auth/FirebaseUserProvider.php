<?php

namespace Isapp\FilamentFirebaseAuth\Auth;

use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DeleteFirebaseUserIfAllowed;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Actions\Identity\PersistResolvedUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\ResolveLocalUser;
use Isapp\FilamentFirebaseAuth\Data\ResolvedUser;
use Isapp\FilamentFirebaseAuth\Enums\DeleteAccounts;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;
use Isapp\FilamentFirebaseAuth\Support\CurrentPlugin;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use LogicException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The `firebase` user provider. Firebase checks the password. The local record never stores one.
 *
 * Laravel keeps one provider instance per guard, also across requests in a long-lived worker.
 * So the provider keeps no state and resolves every action from the container at call time.
 */
class FirebaseUserProvider extends EloquentUserProvider
{
    /**
     * Finds the Firebase user by email and resolves the local record. It never writes.
     *
     * A new record comes back unsaved. `validateCredentials()` saves it after the password check.
     * A blocked sign-in returns null. On a closed panel, the deletion policy may then delete
     * the Firebase account, but only after Firebase proved the password.
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        try {
            $firebaseUser = app(FindFirebaseUserByEmail::class)->handle($email);

            if (! $firebaseUser instanceof UserRecord) {
                return null;
            }

            $createOnSignIn = $this->shouldCreateOnSignIn();
            $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, $this->getModel(), $createOnSignIn);

            if ($resolved->resolution === Resolution::Blocked) {
                $this->deleteIfBlockedOnClosedPanel($firebaseUser, $createOnSignIn, $email, $credentials['password'] ?? null);

                return null;
            }

            return $resolved->user instanceof Model ? $resolved->user : null;
        } catch (FirebaseException $exception) {
            $this->logFirebaseFailure($exception);

            return null;
        }
    }

    /**
     * Checks the password with Firebase, then saves the record and its link.
     *
     * Filament checks the same credentials twice in one request. `CheckFirebasePassword` asks
     * Firebase only once, and the second save finds the record that the first one created.
     */
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! is_string($email) || ! is_string($password) || ! $user instanceof Model) {
            return false;
        }

        try {
            $firebaseUser = app(CheckFirebasePassword::class)->handle($email, $password);

            if (! $firebaseUser instanceof UserRecord || $firebaseUser->disabled) {
                return false;
            }

            // The provider keeps no state, so it resolves the record again. The given record must be that record.
            $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, $this->getModel(), $this->shouldCreateOnSignIn());

            if (! $this->isResolvedRecord($user, $resolved)) {
                return false;
            }

            $saved = app(PersistResolvedUser::class)->handle(
                new ResolvedUser($resolved->resolution, $user),
                $firebaseUser,
                $this->panel(),
            );
        } catch (FirebaseException $exception) {
            $this->logFirebaseFailure($exception);

            return false;
        } catch (QueryException $exception) {
            // For example, a soft deleted record holds the email. The driver message can contain the email.
            app(LoggerInterface::class)->warning('The local record could not be saved after the password check.', [
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return false;
        } catch (LogicException $exception) {
            // A configuration error, for example a NOT NULL password column. The plugin writes these
            // messages. They name tables and columns, never personal data.
            app(LoggerInterface::class)->error($exception->getMessage(), ['exception' => $exception::class]);

            return false;
        }

        $this->takeSavedRecord($user, $saved);

        return true;
    }

    /**
     * The plugin never stores a password, so there is nothing to rehash.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void {}

    /**
     * Runs the deletion policy only when a closed panel blocked a Firebase user without a record,
     * and only after Firebase proved the password (Review Focus 1).
     */
    protected function deleteIfBlockedOnClosedPanel(UserRecord $firebaseUser, bool $createOnSignIn, string $email, mixed $password): void
    {
        if ($createOnSignIn || ! is_string($password) || $this->deleteAccounts() === DeleteAccounts::Never) {
            return;
        }

        // An open panel would create the record. So the closed panel is the only reason for the block.
        $resolution = app(ResolveLocalUser::class)->handle($firebaseUser, $this->getModel(), true)->resolution;

        if ($resolution !== Resolution::New) {
            return;
        }

        $proven = app(CheckFirebasePassword::class)->handle($email, $password);

        if (! $proven instanceof UserRecord) {
            return;
        }

        app(DeleteFirebaseUserIfAllowed::class)->handle($proven, DeletionReason::BlockedOnClosedPanel);
    }

    /**
     * A new record must still be unsaved. Any other record must be the resolved record.
     */
    protected function isResolvedRecord(Model $user, ResolvedUser $resolved): bool
    {
        return match ($resolved->resolution) {
            Resolution::Blocked => false,
            Resolution::New => ! $user->exists
                && $resolved->user instanceof Model
                && $user::class === $resolved->user::class,
            Resolution::Linked, Resolution::LinkedByEmail => $user->exists
                && $resolved->user instanceof Model
                && $user->is($resolved->user),
        };
    }

    /**
     * `CreatesLocalUsers` returns a new instance. Filament keeps the instance from
     * `retrieveByCredentials()` and compares its key later, so that instance takes the saved state.
     */
    protected function takeSavedRecord(Model $user, Model $saved): void
    {
        if ($user === $saved) {
            return;
        }

        $user->setRawAttributes($saved->getAttributes(), true);
        $user->setRelations($saved->getRelations());
        $user->exists = $saved->exists;
        $user->wasRecentlyCreated = $saved->wasRecentlyCreated;
    }

    /**
     * Outside a panel of the plugin, the provider never creates records.
     */
    protected function shouldCreateOnSignIn(): bool
    {
        return app(CurrentPlugin::class)->get()?->shouldCreateOnSignIn() ?? false;
    }

    protected function panel(): ?Panel
    {
        try {
            return app(FilamentManager::class)->getCurrentOrDefaultPanel();
        } catch (NoDefaultPanelSetException) {
            return null;
        }
    }

    protected function deleteAccounts(): DeleteAccounts
    {
        return DeleteAccounts::from(app(Repository::class)->string('filament-firebase-auth.delete_accounts'));
    }

    /**
     * Logs the exception class only. A transport message can contain the request URL with the API key.
     */
    protected function logFirebaseFailure(FirebaseException $exception): void
    {
        app(LoggerInterface::class)->error('Firebase failed during an email and password sign-in.', [
            'exception' => $exception::class,
        ]);
    }
}
