<?php

namespace Isapp\FilamentFirebaseAuth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\CreateLocalUser;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser;
use Isapp\FilamentFirebaseAuth\Auth\FirebaseUserProvider;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Deletion\AccountDeletionPolicy;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentFirebaseAuthServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('filament-firebase-auth')
            ->hasConfigFile()
            ->hasMigration('create_firebase_identities_table')
            ->hasTranslations()
            ->hasViews('filament-firebase-auth')
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->registerActions();
    }

    public function packageBooted(): void
    {
        $this->registerUserProvider();
    }

    /**
     * Binds the default local user actions and the default deletion policy.
     * A host app can bind its own classes to the three contracts.
     * The three Firebase read actions remember their answers, so the container keeps one instance per request.
     */
    private function registerActions(): void
    {
        $this->app->bindIf(CreatesLocalUsers::class, CreateLocalUser::class);
        $this->app->bindIf(SyncsLocalUsers::class, SyncLocalUser::class);
        $this->app->bindIf(DecidesAccountDeletion::class, AccountDeletionPolicy::class);
        $this->app->scoped(FindFirebaseUser::class);
        $this->app->scoped(FindFirebaseUserByEmail::class);
        $this->app->scoped(CheckFirebasePassword::class);
    }

    /**
     * Registers the `firebase` user provider driver. It reads the model from the provider config,
     * the way Laravel's `eloquent` driver does.
     */
    private function registerUserProvider(): void
    {
        Auth::provider('firebase', fn (Application $app, array $config): FirebaseUserProvider => new FirebaseUserProvider(
            $app->make('hash'),
            $config['model'],
        ));
    }
}
