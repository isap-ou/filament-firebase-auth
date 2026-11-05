<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Panels;

use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication as FilamentAppAuthentication;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled as FilamentMultiFactorMiddleware;
use Filament\Panel;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Isapp\FilamentFirebaseAuth\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Isapp\FilamentFirebaseAuth\MultiFactor\AppAuthentication;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use LogicException;

/**
 * Stops a Firebase panel that uses Filament's own registration or profile page.
 * Those pages store a local password, and the plugin never stores one.
 *
 * It also stops Filament's own app authentication and required multi-factor middleware.
 * The app authentication checks a local password, and the middleware sends a user without
 * a password to a set-up page that the user cannot complete.
 */
class EnsurePanelUsesFirebasePages
{
    public function __construct(protected Repository $config) {}

    /**
     * Does nothing when the user provider of the panel guard does not use the `firebase` driver.
     *
     * @throws LogicException when the panel uses a registration or profile page that is not a plugin page,
     *                        or Filament's own app authentication or required multi-factor middleware.
     */
    public function handle(Panel $panel): void
    {
        if (! $this->usesFirebaseDriver($panel)) {
            return;
        }

        if ($panel->hasRegistration() && ! $this->isPage($panel->getRegistrationRouteAction(), Register::class)) {
            throw new LogicException($this->message(
                $panel,
                'registration',
                $panel->getRegistrationRouteAction(),
                '->registration(\\' . Register::class . '::class)',
            ));
        }

        if ($panel->hasProfile() && ! $this->isPage($panel->getProfilePage(), EditProfile::class)) {
            throw new LogicException($this->message(
                $panel,
                'profile',
                $panel->getProfilePage(),
                '->profile(\\' . EditProfile::class . '::class)',
            ));
        }

        $this->ensureFirebaseMultiFactorAuthentication($panel);
    }

    /**
     * @throws LogicException when the panel uses Filament's own app authentication or required multi-factor middleware.
     */
    protected function ensureFirebaseMultiFactorAuthentication(Panel $panel): void
    {
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider instanceof FilamentAppAuthentication && ! $provider instanceof AppAuthentication) {
                throw new LogicException(
                    "The panel [{$panel->getId()}] uses the firebase user provider, but its app authentication is [" . $provider::class . ']. '
                    . "Filament's own app authentication checks a local password, and this plugin never stores one. "
                    . 'Use \\' . AppAuthentication::class . '::make() in ->multiFactorAuthentication() in the panel configuration.',
                );
            }
        }

        if (! $panel->hasMultiFactorAuthentication() || ! $panel->isMultiFactorAuthenticationRequired()) {
            return;
        }

        $middleware = Str::before($panel->getMultiFactorAuthenticationRequiredMiddlewareName(), ':');

        if (is_a($middleware, FilamentMultiFactorMiddleware::class, true) && ! is_a($middleware, EnsureMultiFactorAuthenticationIsEnabled::class, true)) {
            throw new LogicException(
                "The panel [{$panel->getId()}] uses the firebase user provider and requires multi-factor authentication, but its middleware is [{$middleware}]. "
                . "Filament's own middleware sends a user without a password to a set-up page that the user cannot complete. "
                . 'Use ->multiFactorAuthenticationRequiredMiddlewareName(\\' . EnsureMultiFactorAuthenticationIsEnabled::class . '::class) in the panel configuration.',
            );
        }
    }

    protected function usesFirebaseDriver(Panel $panel): bool
    {
        $provider = $this->config->get("auth.guards.{$panel->getAuthGuard()}.provider");

        if (! is_string($provider)) {
            return false;
        }

        return $this->config->get("auth.providers.{$provider}.driver") === 'firebase';
    }

    /**
     * @param  class-string  $page
     */
    protected function isPage(mixed $action, string $page): bool
    {
        return is_string($action) && is_a($action, $page, true);
    }

    /**
     * @param  string | Closure | array<array-key, mixed> | null  $action
     */
    protected function message(Panel $panel, string $pageName, string | Closure | array | null $action, string $fix): string
    {
        $current = is_string($action) ? $action : get_debug_type($action);

        return "The panel [{$panel->getId()}] uses the firebase user provider, but its {$pageName} page is [{$current}]. "
            . "Filament's own page stores a local password, and this plugin never stores one. "
            . "Use {$fix} in the panel configuration.";
    }
}
