<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Support\CurrentPlugin;

it('has the plugin id and open defaults', function () {
    $plugin = FilamentFirebaseAuthPlugin::make();

    expect($plugin->getId())->toBe('filament-firebase-auth')
        ->and($plugin->shouldCreateOnSignIn())->toBeTrue()
        ->and($plugin->getProviders())->toBe([]);
});

it('stores the panel settings', function () {
    $plugin = FilamentFirebaseAuthPlugin::make()
        ->createOnSignIn(false)
        ->providers([SocialProvider::Google, SocialProvider::GitHub]);

    expect($plugin->shouldCreateOnSignIn())->toBeFalse()
        ->and($plugin->getProviders())->toBe([SocialProvider::Google, SocialProvider::GitHub]);
});

it('evaluates a closure for create on sign-in', function () {
    $open = false;
    $plugin = FilamentFirebaseAuthPlugin::make()->createOnSignIn(function () use (&$open): bool {
        return $open;
    });

    expect($plugin->shouldCreateOnSignIn())->toBeFalse();

    $open = true;

    expect($plugin->shouldCreateOnSignIn())->toBeTrue();
});

it('returns the plugin of the current panel', function () {
    $plugin = FilamentFirebaseAuthPlugin::make()->createOnSignIn(false);
    Filament::setCurrentPanel(Panel::make()->id('admin')->plugin($plugin));

    expect(app(CurrentPlugin::class)->get())->toBe($plugin);
});

it('returns null when the current panel has no plugin', function () {
    Filament::setCurrentPanel(Panel::make()->id('admin'));

    expect(app(CurrentPlugin::class)->get())->toBeNull();
});

it('returns null when there is no panel', function () {
    expect(app(CurrentPlugin::class)->get())->toBeNull();
});
