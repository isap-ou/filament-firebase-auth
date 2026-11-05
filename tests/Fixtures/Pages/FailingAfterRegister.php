<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages;

use RuntimeException;

/**
 * A transactional registration page whose `afterRegister` hook fails after the save step.
 */
class FailingAfterRegister extends TransactionalRegister
{
    protected function afterRegister(): void
    {
        throw new RuntimeException('The afterRegister hook failed.');
    }
}
