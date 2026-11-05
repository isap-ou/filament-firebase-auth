<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Panels;

use Closure;
use Filament\Panel;
use Illuminate\Contracts\Config\Repository;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;
use LogicException;

/**
 * Stops a Firebase panel that uses Filament's own registration or profile page.
 * Those pages store a local password, and the plugin never stores one.
 */
class EnsurePanelUsesFirebasePages
{
    public function __construct(protected Repository $config) {}

    /**
     * Does nothing when the user provider of the panel guard does not use the `firebase` driver.
     *
     * @throws LogicException when the panel uses a registration or profile page that is not a plugin page.
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
