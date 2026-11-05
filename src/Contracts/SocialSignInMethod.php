<?php

namespace Isapp\FilamentFirebaseAuth\Contracts;

use Filament\Panel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Htmlable;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Kreait\Firebase\Auth\UserRecord;
use SensitiveParameter;

/**
 * A way to sign in with a social provider. The config key `social_sign_in` selects it.
 *
 * The method renders the buttons and proves the Firebase user. The rest of the flow
 * (link lookup, create on sign-in, deletion policy, login) does not depend on the method.
 */
interface SocialSignInMethod
{
    /**
     * Renders the buttons for the providers, in the given order.
     *
     * @param  array<SocialProvider>  $providers
     */
    public function renderButtons(Panel $panel, array $providers): Htmlable;

    /**
     * Returns the Firebase user that the credential proves.
     *
     * @throws AuthenticationException on any failure. The exception has no detail.
     */
    public function verify(#[SensitiveParameter] string $credential): UserRecord;
}
