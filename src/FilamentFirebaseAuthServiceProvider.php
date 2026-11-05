<?php

namespace Isapp\FilamentFirebaseAuth;

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
}
