<?php

namespace Isapp\FilamentFirebaseAuth;

use Isapp\FilamentFirebaseAuth\Actions\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\CreateLocalUser;
use Isapp\FilamentFirebaseAuth\Actions\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\SyncLocalUser;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
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

    /**
     * Binds the default local user actions. A host app can bind its own classes to the two contracts.
     * The two Firebase read actions remember their answers, so the container keeps one instance per request.
     */
    private function registerActions(): void
    {
        $this->app->bindIf(CreatesLocalUsers::class, CreateLocalUser::class);
        $this->app->bindIf(SyncsLocalUsers::class, SyncLocalUser::class);
        $this->app->scoped(FindFirebaseUser::class);
        $this->app->scoped(CheckFirebasePassword::class);
    }
}
