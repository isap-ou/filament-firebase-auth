<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models;

use Filament\Panel;

/**
 * A fixture user of the `users` table that lost access to every panel.
 */
class UserWithoutPanelAccess extends User
{
    public function canAccessPanel(Panel $panel): bool
    {
        return false;
    }
}
