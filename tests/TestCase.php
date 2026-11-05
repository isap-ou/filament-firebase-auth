<?php

namespace Isapp\FilamentFirebaseAuth\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthServiceProvider;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Panels\AdminPanelProvider;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Panels\AppPanelProvider;
use Kreait\Laravel\Firebase\ServiceProvider as FirebaseServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;

    protected function getPackageProviders($app)
    {
        $providers = [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FirebaseServiceProvider::class,
            FilamentFirebaseAuthServiceProvider::class,
            AppPanelProvider::class,
            AdminPanelProvider::class,
        ];

        sort($providers);

        return $providers;
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');

        $this->defineAuthConfig($app);
    }

    /**
     * Defines all guards, user providers and password brokers of the fixture panels.
     * The `firebase_*` providers use the `eloquent` driver until the plugin has its `firebase` driver.
     * The `users` broker keeps Laravel's default driver.
     */
    protected function defineAuthConfig($app): void
    {
        $app['config']->set('auth.guards.app', ['driver' => 'session', 'provider' => 'firebase_users']);
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'firebase_admins']);

        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
        $app['config']->set('auth.providers.firebase_users', ['driver' => 'eloquent', 'model' => User::class]);
        $app['config']->set('auth.providers.firebase_admins', ['driver' => 'eloquent', 'model' => Admin::class]);

        $app['config']->set('auth.passwords.users', [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);
        $app['config']->set('auth.passwords.users_app', [
            'driver' => 'firebase',
            'provider' => 'firebase_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);
        $app['config']->set('auth.passwords.admins', [
            'driver' => 'firebase',
            'provider' => 'firebase_admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/database/migrations');
    }
}
