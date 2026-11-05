<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Panels;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Isapp\FilamentFirebaseAuth\Pages\EditProfile;
use Isapp\FilamentFirebaseAuth\Pages\Register;

/**
 * The open fixture panel: registration, profile, email verification and password reset.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->authGuard('app')
            ->authPasswordBroker('users_app')
            ->login()
            ->registration(Register::class)
            ->profile(EditProfile::class)
            ->emailVerification()
            ->emailChangeVerification()
            ->passwordReset()
            ->plugin(
                FilamentFirebaseAuthPlugin::make()
                    ->createOnSignIn(true)
                    ->providers([SocialProvider::Google]),
            )
            ->pages([Dashboard::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
