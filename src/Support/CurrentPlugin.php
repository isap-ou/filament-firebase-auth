<?php

namespace Isapp\FilamentFirebaseAuth\Support;

use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\FilamentManager;
use Filament\Panel;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;

/**
 * Finds the plugin of the current panel. Resolve it from the container.
 */
final readonly class CurrentPlugin
{
    public function __construct(private FilamentManager $filament) {}

    /**
     * Returns the plugin of the current panel, or of the default panel outside a panel request.
     * Returns null when there is no panel or the panel does not use the plugin.
     */
    public function get(): ?FilamentFirebaseAuthPlugin
    {
        try {
            $panel = $this->filament->getCurrentOrDefaultPanel();
        } catch (NoDefaultPanelSetException) {
            return null;
        }

        if (! $panel instanceof Panel || ! $panel->hasPlugin('filament-firebase-auth')) {
            return null;
        }

        $plugin = $panel->getPlugin('filament-firebase-auth');

        return $plugin instanceof FilamentFirebaseAuthPlugin ? $plugin : null;
    }
}
