<?php

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\Admin;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('keeps the native login page', function () {
    expect(Filament::getPanel('app')->getLoginRouteAction())->toBe(Login::class)
        ->and(Filament::getPanel('admin')->getLoginRouteAction())->toBe(Login::class);
});

it('uses the plugin pages that the fixture panels select', function () {
    expect(Filament::getPanel('app')->getRegistrationRouteAction())->toBe(Register::class)
        ->and(Filament::getPanel('app')->getProfilePage())->toBe(EditProfile::class)
        ->and(Filament::getPanel('admin')->hasRegistration())->toBeFalse()
        ->and(Filament::getPanel('admin')->getProfilePage())->toBe(EditProfile::class)
        ->and(Filament::getPanel('app')->getPlugin('filament-firebase-auth'))->toBeInstanceOf(FilamentFirebaseAuthPlugin::class)
        ->and(Filament::getPanel('admin')->getPlugin('filament-firebase-auth'))->toBeInstanceOf(FilamentFirebaseAuthPlugin::class);
});

it('renders the native login page', function () {
    // @phpstan-ignore method.notFound (Livewire adds assertSeeLivewire() to TestResponse as a macro.)
    get('/app/login')->assertOk()->assertSeeLivewire(Login::class);
});

it('renders the plugin registration page', function () {
    // @phpstan-ignore method.notFound (Livewire adds assertSeeLivewire() to TestResponse as a macro.)
    get('/app/register')->assertOk()->assertSeeLivewire(Register::class);
});

it('renders the plugin profile page', function () {
    $admin = Admin::query()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

    actingAs($admin, 'admin')
        ->get('/admin/profile')
        ->assertOk()
        // @phpstan-ignore method.notFound (Livewire adds assertSeeLivewire() to TestResponse as a macro.)
        ->assertSeeLivewire(EditProfile::class);
});

it('checks the pages when the panel boots', function () {
    config()->set('auth.providers.firebase_guarded', ['driver' => 'firebase', 'model' => Admin::class]);
    config()->set('auth.guards.firebase_guarded', ['driver' => 'session', 'provider' => 'firebase_guarded']);

    $panel = Panel::make()
        ->id('guarded')
        ->authGuard('firebase_guarded')
        ->registration()
        ->plugin(FilamentFirebaseAuthPlugin::make());

    expect(fn () => $panel->boot())
        ->toThrow(LogicException::class, '->registration(\Isapp\FilamentFirebaseAuth\Pages\Register::class)');
});
