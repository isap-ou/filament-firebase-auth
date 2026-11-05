<?php

namespace Isapp\FilamentFirebaseAuth;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Actions\Panels\EnsurePanelUsesFirebasePages;
use Isapp\FilamentFirebaseAuth\Http\Middleware\ScheduleFirebaseSessionCheck;

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

    public function register(Panel $panel): void
    {
        $this->registerSessionCheck($panel);
    }

    /**
     * Filament calls this method on the first request of the panel.
     * A Firebase panel with Filament's own registration or profile page then fails at once.
     */
    public function boot(Panel $panel): void
    {
        app(EnsurePanelUsesFirebasePages::class)->handle($panel);
    }

    /**
     * Adds the background session check. Laravel's `AuthenticateSession` does the logout,
     * so the plugin adds Filament's subclass when the panel has no session authentication yet.
     *
     * The check is persistent middleware, so Livewire update requests run it too.
     * Filament makes `AuthenticateSession` persistent already.
     *
     * Filament calls `register()` when the panel adds the plugin. Middleware that the panel adds
     * later is not visible here. The router removes a second entry of the same class.
     */
    private function registerSessionCheck(Panel $panel): void
    {
        $panel->authMiddleware([ScheduleFirebaseSessionCheck::class], isPersistent: true);

        foreach ($panel->getMiddleware() as $middleware) {
            if (is_a(Str::before($middleware, ':'), AuthenticatesSessions::class, true)) {
                return;
            }
        }

        $panel->middleware([AuthenticateSession::class]);
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
