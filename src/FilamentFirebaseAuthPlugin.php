<?php

namespace Isapp\FilamentFirebaseAuth;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Isapp\FilamentFirebaseAuth\Actions\Panels\EnsurePanelUsesFirebasePages;

/**
 * The per-panel settings of the plugin.
 */
class FilamentFirebaseAuthPlugin implements Plugin
{
    use EvaluatesClosures;

    protected bool | Closure $createOnSignIn = true;

    /**
     * @var list<string>
     */
    protected array $providers = [];

    /**
     * Filament's plugin docs use a static `make()`. The `Plugin` contract does not require it.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-firebase-auth';
    }

    public function register(Panel $panel): void {}

    /**
     * Filament calls this method on the first request of the panel.
     * A Firebase panel with Filament's own registration or profile page then fails at once.
     */
    public function boot(Panel $panel): void
    {
        app(EnsurePanelUsesFirebasePages::class)->handle($panel);
    }

    /**
     * When true, a sign-in with a Firebase account that has no local record creates one.
     * When false, the panel is closed: only linked or linkable records sign in.
     */
    public function createOnSignIn(bool | Closure $condition = true): static
    {
        $this->createOnSignIn = $condition;

        return $this;
    }

    public function shouldCreateOnSignIn(): bool
    {
        return (bool) $this->evaluate($this->createOnSignIn);
    }

    /**
     * The Firebase provider IDs that get a button on the login page, for example `google.com`.
     *
     * @param  array<string>  $providerIds
     */
    public function providers(array $providerIds): static
    {
        $this->providers = array_values($providerIds);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
