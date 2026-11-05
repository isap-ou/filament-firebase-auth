<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Signs the current session of the panel in again after the session fingerprint changed.
 *
 * Laravel's AuthenticateSession logs a session out when its stored fingerprint differs from
 * `getAuthPassword()`. A new sign-in stores the new fingerprint in this session. It also queues
 * a new remember cookie when the request has one, because the old cookie holds the old fingerprint.
 * Other sessions keep the old fingerprint and log out on their next request.
 */
class RenewCurrentSession
{
    public function __construct(protected Request $request) {}

    public function handle(Authenticatable $user): void
    {
        $guard = Filament::auth();
        $remember = $guard instanceof SessionGuard && $this->request->cookies->has($guard->getRecallerName());

        $guard->login($user, $remember);
    }
}
