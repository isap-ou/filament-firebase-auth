<?php

namespace Isapp\FilamentFirebaseAuth\Auth;

use Closure;
use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * The provider reads the plugin settings only from the current panel, and only when the guard
 * of that panel uses this provider instance. Outside such a panel (for example
 * `Auth::guard('admin')->attempt()` in host code, or a request of another panel), the provider
 * never creates a record, gives no panel to `CreatesLocalUsers` and never deletes an account.
 */
class FirebaseUserProvider extends EloquentUserProvider
{
    /**
     * Finds the Firebase user by email and resolves the local record. It never writes.
     *
     * A new record comes back unsaved. `validateCredentials()` saves it after the password check.
     * An unknown email or a blocked sign-in returns null. The password check still runs, so every
     * attempt makes the same number of Firebase calls and takes about the same time.
     * On a closed panel, the deletion policy may then delete the Firebase account,
     * but only after Firebase proved the password.
     *
     * Credentials other than the email and the password, and the `withQuery()` callback, limit the
     * local record, as in Laravel's eloquent driver.
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        try {
            $firebaseUser = app(FindFirebaseUserByEmail::class)->handle($email);

            if (! $firebaseUser instanceof UserRecord) {
                $this->checkPassword($email, $password);

                return null;
            }

            $panel = $this->panel();
            $createOnSignIn = $this->shouldCreateOnSignIn($panel);
            $resolved = app(ResolveLocalUser::class)->handle($firebaseUser, $this->getModel(), $createOnSignIn, $this->constraints($credentials));

            if ($resolved->resolution === Resolution::Blocked) {
                $proven = $this->checkPassword($email, $password);

                if ($panel instanceof Panel && ! $createOnSignIn && $proven instanceof UserRecord) {
                    $this->deleteIfBlockedOnClosedPanel($firebaseUser, $proven);
                }

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
            $panel = $this->panel();
            $resolved = app(ResolveLocalUser::class)->handle(
                $firebaseUser,
                $this->getModel(),
                $this->shouldCreateOnSignIn($panel),
                $this->constraints($credentials),
            );

            if (! $this->isResolvedRecord($user, $resolved)) {
                return false;
            }

            $saved = app(PersistResolvedUser::class)->handle(
                new ResolvedUser($resolved->resolution, $user),
                $firebaseUser,
                $panel,
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
            $this->logSaveFailure($exception);

            return false;
        }

        $this->takeSavedRecord($user, $saved);

        return true;
    }

    /**
     * Builds the conditions of `EloquentUserProvider::retrieveByCredentials()` for the local record:
     * the `withQuery()` callback, and each credential except the email and the password keys.
     * A list becomes `whereIn()`, and a closure gets the query.
     *
     * @param  array<array-key, mixed>  $credentials
     * @return (Closure(Builder<Model>): void)|null
     */
    protected function constraints(#[SensitiveParameter] array $credentials): ?Closure
    {
        $conditions = array_filter(
            $credentials,
            fn ($key): bool => $key !== 'email' && ! str_contains((string) $key, 'password'),
            ARRAY_FILTER_USE_KEY,
        );
        $callback = $this->getQueryCallback();

        if ($conditions === [] && $callback === null) {
            return null;
        }

        return function (Builder $query) use ($conditions, $callback): void {
            if ($callback !== null) {
                $callback($query);
            }

            foreach ($conditions as $key => $value) {
                if (is_array($value) || $value instanceof Arrayable) {
                    $query->whereIn((string) $key, $value);
                } elseif ($value instanceof Closure) {
                    $value($query);
                } else {
                    $query->where((string) $key, $value);
                }
            }
        };
    }

    /**
     * The plugin never stores a password, so there is nothing to rehash.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void {}

    /**
     * Checks the password also when the sign-in fails anyway. Without it, an attempt for an email
     * with a local record would make one more Firebase call than any other attempt, and the
     * timing would show which emails have a record. `CheckFirebasePassword` remembers the
     * answer, so `validateCredentials()` and the deletion path do not ask Firebase again.
     */
    protected function checkPassword(string $email, mixed $password): ?UserRecord
    {
        return is_string($password) ? app(CheckFirebasePassword::class)->handle($email, $password) : null;
    }

    /**
     * Runs the deletion policy only when a closed panel blocked a Firebase user without a record.
     * The caller gives the Firebase user only after Firebase proved the password (Review Focus 1).
     */
    protected function deleteIfBlockedOnClosedPanel(UserRecord $firebaseUser, UserRecord $proven): void
    {
        if ($this->deleteAccounts() === DeleteAccounts::Never) {
            return;
        }

        // An open panel would create the record. So the closed panel is the only reason for the block.
        $resolution = app(ResolveLocalUser::class)->handle($firebaseUser, $this->getModel(), true)->resolution;

        if ($resolution !== Resolution::New) {
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
     * Without a panel of this provider, the provider never creates records.
     * With a panel, `CurrentPlugin` reads the plugin of that panel, because it is the current panel.
     */
    protected function shouldCreateOnSignIn(?Panel $panel): bool
    {
        if (! $panel instanceof Panel) {
            return false;
        }

        return app(CurrentPlugin::class)->get()?->shouldCreateOnSignIn() ?? false;
    }

    /**
     * Returns the current panel when its guard uses this provider instance, or null.
     * Laravel builds one provider instance per guard, so another guard never matches.
     */
    protected function panel(): ?Panel
    {
        $panel = app(FilamentManager::class)->getCurrentPanel();

        if (! $panel instanceof Panel) {
            return null;
        }

        $guard = app(AuthFactory::class)->guard($panel->getAuthGuard());

        return $guard instanceof SessionGuard && $guard->getProvider() === $this ? $panel : null;
    }

    protected function deleteAccounts(): DeleteAccounts
    {
        return DeleteAccounts::from(app(Repository::class)->string('filament-firebase-auth.delete_accounts'));
    }

    /**
     * Logs the message only when plugin code created the exception. The plugin messages name
     * tables and columns, for example the NOT NULL password column, never personal data.
     * Host code (for example its own `CreatesLocalUsers`) may put personal data in a message,
     * so its exceptions are logged with the class only.
     */
    protected function logSaveFailure(LogicException $exception): void
    {
        $message = str_starts_with($exception->getFile(), dirname(__DIR__) . DIRECTORY_SEPARATOR)
            ? $exception->getMessage()
            : 'The local record could not be saved after the password check.';

        app(LoggerInterface::class)->error($message, ['exception' => $exception::class]);
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
