<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages;

use Isapp\FilamentFirebaseAuth\Pages\Register;

/**
 * The plugin registration page with Filament's page transaction turned on.
 */
class TransactionalRegister extends Register
{
    protected ?bool $hasDatabaseTransactions = true;
}
