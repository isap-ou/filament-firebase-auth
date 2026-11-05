<?php

namespace Isapp\FilamentFirebaseAuth\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled as FilamentMiddleware;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\DetectUserWithoutPassword;

/**
 * Filament's required multi-factor middleware for a Firebase panel.
 * The host selects it with `->multiFactorAuthenticationRequiredMiddlewareName(...)`.
 *
 * A user without the Firebase `password` provider passes: that user signs in through a social
 * provider, and the social provider owns the second factor. Every other user gets Filament's behaviour.
 */
class EnsureMultiFactorAuthenticationIsEnabled extends FilamentMiddleware
{
    public function __construct(protected DetectUserWithoutPassword $detectUserWithoutPassword) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        // The local check comes first, so a user with multi-factor authentication causes no Firebase call.
        if (
            $user instanceof Authenticatable
            && ! MultiFactorChallenge::make()->hasEnabledProviders($user)
            && $this->detectUserWithoutPassword->handle($user)
        ) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
