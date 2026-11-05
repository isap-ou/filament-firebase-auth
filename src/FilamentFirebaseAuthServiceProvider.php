<?php

namespace Isapp\FilamentFirebaseAuth;

use Filament\Auth\Http\Requests\EmailChangeVerificationRequest as FilamentEmailChangeVerificationRequest;
use Filament\FilamentManager;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\CheckFirebasePassword;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUserByEmail;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\CreateLocalUser;
use Isapp\FilamentFirebaseAuth\Actions\LocalUsers\SyncLocalUser;
use Isapp\FilamentFirebaseAuth\Auth\FirebaseUserProvider;
use Isapp\FilamentFirebaseAuth\Contracts\CreatesLocalUsers;
use Isapp\FilamentFirebaseAuth\Contracts\DecidesAccountDeletion;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Deletion\AccountDeletionPolicy;
use Isapp\FilamentFirebaseAuth\Http\Requests\EmailChangeVerificationRequest;
use Isapp\FilamentFirebaseAuth\Passwords\FirebasePasswordBrokerManager;
use Isapp\FilamentFirebaseAuth\SocialSignIn\JsSdkSignIn;
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
        $this->registerPasswordBroker();
        $this->registerProfile();
    }

    public function packageBooted(): void
    {
        $this->registerUserProvider();
        $this->registerCommands();
        $this->registerSocialSignIn();
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
     * Gives Filament's email-change controller the plugin request, which changes the Firebase email.
     */
    private function registerProfile(): void
    {
        $this->app->bind(FilamentEmailChangeVerificationRequest::class, EmailChangeVerificationRequest::class);
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

    /**
     * Replaces Filament's `make:filament-user` command and its aliases.
     *
     * The command has no `#[AsCommand]` attribute, so Artisan adds it as an object. An added object
     * wins over a lazy command with the same name, whichever provider registers first. The callback
     * runs after all providers booted, so the plugin command is also added after an eager Filament command.
     */
    private function registerCommands(): void
    {
        $this->app->booted(fn () => $this->commands([Commands\MakeFirebaseUserCommand::class]));
    }

    /**
     * Replaces Laravel's password broker manager with a subclass that also builds the `firebase`
     * broker driver. Laravel's manager has no `extend()` hook. `auth.password.broker` and the
     * `Password` facade resolve through `auth.password`, so they use the subclass too.
     */
    private function registerPasswordBroker(): void
    {
        $this->app->extend('auth.password', fn (PasswordBrokerManager $manager, Application $app): FirebasePasswordBrokerManager => new FirebasePasswordBrokerManager($app));
    }

    /**
     * Registers the JS bundle of the social buttons. It loads only on a page that shows the buttons.
     * Binds the social sign-in method that the config key `social_sign_in` selects.
     * A host app can bind its own class to the contract.
     * Defines the rate limiter of the sign-in route: 5 attempts per minute for each panel and IP address,
     * the limit of Filament's login form.
     */
    private function registerSocialSignIn(): void
    {
        FilamentAsset::register([
            Js::make('filament-firebase-auth', __DIR__ . '/../resources/dist/filament-firebase-auth.js')->loadedOnRequest(),
        ], 'isapp/filament-firebase-auth');

        $this->app->bindIf(SocialSignInMethod::class, function (Application $app): SocialSignInMethod {
            $method = $app->make('config')->get('filament-firebase-auth.social_sign_in');

            if ($method !== 'js_sdk') {
                $name = is_string($method) ? $method : get_debug_type($method);

                throw new InvalidArgumentException("The social sign-in method [{$name}] is not supported. Use \"js_sdk\".");
            }

            return $app->make(JsSdkSignIn::class);
        });

        RateLimiter::for('filament-firebase-auth', fn (Request $request): Limit => Limit::perMinute(5)->by(
            'filament-firebase-auth:' . $this->app->make(FilamentManager::class)->getCurrentPanel()?->getId() . ':' . $request->ip(),
        ));
    }
}
