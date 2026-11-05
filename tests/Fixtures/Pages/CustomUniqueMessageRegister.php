<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Pages;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Isapp\FilamentFirebaseAuth\Pages\Register;

/**
 * A host registration page with its own message for the `unique` rule of the email field.
 */
class CustomUniqueMessageRegister extends Register
{
    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();

        if ($component instanceof Field) {
            $component->validationMessages(['unique' => 'The host says :attribute is taken.']);
        }

        return $component;
    }
}
