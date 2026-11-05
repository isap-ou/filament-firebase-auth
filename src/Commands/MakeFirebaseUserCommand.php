<?php

namespace Isapp\FilamentFirebaseAuth\Commands;

use Filament\Commands\MakeUserCommand;
use Filament\Support\Commands\Exceptions\FailureCommandOutput;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DeleteFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindOrCreateFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\LinkFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Auth\FirebaseUserProvider;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Replaces Filament's `make:filament-user` command. It keeps the name, the aliases and the options.
 *
 * On a panel whose guard uses the `firebase` driver, Firebase keeps the password and the local
 * record gets none. On any other panel, the command runs Filament's own behaviour.
 *
 * The class has no `#[AsCommand]` attribute on purpose. Artisan then adds it as a command object,
 * and a command object wins over Filament's lazy command with the same name.
 * The actions are resolved at run time, so that other Artisan commands never build a Firebase client.
 */
class MakeFirebaseUserCommand extends MakeUserCommand
{
    /**
     * On a Firebase panel, the password stays plain text, because Firebase receives it.
     *
     * @return array{'name': string, 'email': string, 'password': string}
     */
    protected function getUserData(): array
    {
        if (! $this->usesFirebase()) {
            return parent::getUserData();
        }

        return [
            'name' => $this->options['name'] ?? text(
                label: 'Name',
                required: true,
            ),

            'email' => $this->options['email'] ?? text(
                label: 'Email address',
                required: true,
                validate: fn (string $email): ?string => match (true) {
                    ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'The email address must be valid.',
                    $this->getUserModel()::query()->where('email', $email)->exists() => 'A user with this email address already exists',
                    default => null,
                },
            ),

            'password' => $this->options['password'] ?? password(
                label: 'Password',
                required: true,
            ),
        ];
    }

    /**
     * Stops with exit code 1 and without an exception trace when `createUser()` refuses the account.
     */
    public function handle(): int
    {
        try {
            return parent::handle();
        } catch (FailureCommandOutput) {
            return self::FAILURE;
        }
    }

    /**
     * Finds or creates the Firebase account, then creates the local record and its link.
     *
     * A new account gets `emailVerified = true`, because an admin creates it on purpose.
     * An existing account keeps its password and its verification. An existing account with an
     * unverified email is refused, because anyone can create such an account with another
     * person's email (the rule of `ResolveLocalUser` case 2).
     * When the local step fails, the command deletes the account only if it created that account.
     *
     * @throws FailureCommandOutput when the existing account has an unverified email.
     */
    protected function createUser(): Model & Authenticatable
    {
        if (! $this->usesFirebase()) {
            return parent::createUser();
        }

        $data = $this->getUserData();

        [$firebaseUser, $created] = $this->laravel->make(FindOrCreateFirebaseUser::class)
            ->handle($data['email'], $data['password'], $data['name'], verified: true);

        if (! $created && ! $firebaseUser->emailVerified) {
            $this->components->error(__('filament-firebase-auth::command.unverified_account', ['email' => $data['email']]));

            throw new FailureCommandOutput;
        }

        if (! $created) {
            $this->components->warn(__('filament-firebase-auth::command.existing_account', ['email' => $data['email']]));
        }

        try {
            return $this->createLocalUser($firebaseUser);
        } catch (Throwable $exception) {
            if ($created) {
                $this->deleteCreatedAccount($firebaseUser);
            }

            throw $exception;
        }
    }

    /**
     * Creates the record and the link in one transaction, so that a failed link leaves no record.
     */
    protected function createLocalUser(UserRecord $firebaseUser): Model & Authenticatable
    {
        $model = $this->getUserModel();

        // The transaction runs on the connection of the host model, which writes the record.
        return (new $model)->getConnection()->transaction(function () use ($firebaseUser, $model): Model & Authenticatable {
            $user = $this->laravel->make(CreatesLocalUsers::class)->handle($firebaseUser, $model, $this->panel);

            $this->laravel->make(LinkFirebaseIdentity::class)->handle($user, $firebaseUser);

            return $user;
        });
    }

    /**
     * A failed deletion does not hide the first error. The command names the account,
     * so that the admin can delete it by hand.
     */
    protected function deleteCreatedAccount(UserRecord $firebaseUser): void
    {
        try {
            $this->laravel->make(DeleteFirebaseUser::class)->handle($firebaseUser->uid);
        } catch (FirebaseException) {
            $this->components->error(__('filament-firebase-auth::command.cleanup_failed', ['uid' => $firebaseUser->uid]));
        }
    }

    /**
     * True when the guard of the selected panel uses the `firebase` driver.
     */
    protected function usesFirebase(): bool
    {
        return $this->getUserProvider() instanceof FirebaseUserProvider;
    }
}
