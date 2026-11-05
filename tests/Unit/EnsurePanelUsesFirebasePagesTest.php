<?php

use Filament\Auth\MultiFactor\App\AppAuthentication as NativeAppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Auth\Pages\EditProfile as NativeEditProfile;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Isapp\FilamentFirebaseAuth\Actions\Panels\EnsurePanelUsesFirebasePages;
use Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages\HostEditProfile;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages\HostRegister;

beforeEach(function () {
    config()->set('auth.providers.firebase_checked', ['driver' => 'firebase', 'model' => User::class]);
    config()->set('auth.guards.firebase_checked', ['driver' => 'session', 'provider' => 'firebase_checked']);
});

it('rejects the native registration page on a firebase panel', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')->registration();

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, '->registration(\Isapp\FilamentFirebaseAuth\Pages\Register::class)');
});

it('rejects a registration closure on a firebase panel', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')->registration(fn () => 'custom');

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, '->registration(\Isapp\FilamentFirebaseAuth\Pages\Register::class)');
});

it('rejects the native profile page on a firebase panel', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')->profile(NativeEditProfile::class);

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, '->profile(\Isapp\FilamentFirebaseAuth\Pages\EditProfile::class)');
});

it('accepts plugin pages and their subclasses', function () {
    $action = app(EnsurePanelUsesFirebasePages::class);

    $pluginPages = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->registration(Register::class)
        ->profile(EditProfile::class);
    $hostPages = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->registration(HostRegister::class)
        ->profile(HostEditProfile::class);

    // A direct call: `not->toThrow(LogicException::class)` would also pass on any other exception.
    $action->handle($pluginPages);
    $action->handle($hostPages);

    expect(true)->toBeTrue();
});

it('accepts a firebase panel without registration and profile', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked');

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('ignores panels that do not use the firebase driver', function () {
    config()->set('auth.providers.local_users', ['driver' => 'eloquent', 'model' => User::class]);
    config()->set('auth.guards.local', ['driver' => 'session', 'provider' => 'local_users']);

    $action = app(EnsurePanelUsesFirebasePages::class);

    $localPanel = Panel::make()->id('local')->authGuard('local')->registration()->profile();
    $unknownGuardPanel = Panel::make()->id('unknown')->authGuard('missing')->registration()->profile();

    $action->handle($localPanel);
    $action->handle($unknownGuardPanel);

    expect(true)->toBeTrue();
});

it('rejects filament app authentication on a firebase panel', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->multiFactorAuthentication([NativeAppAuthentication::make()]);

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, '\Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication::make()');
});

it('rejects the native required-mfa middleware on a firebase panel', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->multiFactorAuthentication([AppAuthentication::make()], isRequired: true);

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, '->multiFactorAuthenticationRequiredMiddlewareName(\Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled::class)');
});

it('accepts the plugin app authentication, the plugin middleware and email codes', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->multiFactorAuthentication([AppAuthentication::make(), EmailAuthentication::make()], isRequired: true)
        ->multiFactorAuthenticationRequiredMiddlewareName(EnsureMultiFactorAuthenticationIsEnabled::class);

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('accepts the native required-mfa middleware when mfa is optional', function () {
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')
        ->multiFactorAuthentication([AppAuthentication::make()]);

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('ignores filament mfa on panels that do not use the firebase driver', function () {
    config()->set('auth.providers.local_users', ['driver' => 'eloquent', 'model' => User::class]);
    config()->set('auth.guards.local', ['driver' => 'session', 'provider' => 'local_users']);

    $panel = Panel::make()->id('local')->authGuard('local')
        ->multiFactorAuthentication([NativeAppAuthentication::make()], isRequired: true);

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('rejects a password reset through a broker without the firebase driver', function (?string $broker) {
    // `users` keeps Laravel's default driver. Without `authPasswordBroker()`, Filament uses the default broker.
    config()->set('auth.defaults.passwords', 'users');
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')->passwordReset()->authPasswordBroker($broker);

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, 'the [users] password broker does not use the firebase driver');
})->with([
    'named broker' => 'users',
    'default broker' => null,
]);

it('accepts a password reset through a firebase broker', function () {
    config()->set('auth.passwords.firebase_checked', ['driver' => 'firebase', 'provider' => 'firebase_checked', 'table' => 'password_reset_tokens']);
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked')->passwordReset()->authPasswordBroker('firebase_checked');

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('ignores the broker of a panel without password reset', function () {
    config()->set('auth.defaults.passwords', 'users');
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked');

    app(EnsurePanelUsesFirebasePages::class)->handle($panel);

    expect(true)->toBeTrue();
});

it('rejects a firebase panel whose model does not use the trait', function () {
    config()->set('auth.providers.firebase_checked.model', FoundationUser::class);
    $panel = Panel::make()->id('checked')->authGuard('firebase_checked');

    expect(fn () => app(EnsurePanelUsesFirebasePages::class)->handle($panel))
        ->toThrow(LogicException::class, 'use \\Isapp\\FilamentFirebaseAuth\\Concerns\\HasFirebaseIdentity;');
});
