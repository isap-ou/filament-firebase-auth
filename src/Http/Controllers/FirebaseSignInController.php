<?php

namespace Isapp\FilamentFirebaseAuth\Http\Controllers;

use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Isapp\FilamentFirebaseAuth\Actions\Accounts\SignInWithFirebaseCredential;

/**
 * Receives the credential of a social sign-in on the plugin route of a panel.
 *
 * The route sits inside the panel route group, so it has the panel middleware with the session
 * and the CSRF check. The `panel:` middleware sets the current panel.
 */
class FirebaseSignInController
{
    public function __construct(
        protected SignInWithFirebaseCredential $signInWithFirebaseCredential,
        protected FilamentManager $filament,
    ) {}

    /**
     * Reads the credential from the JSON body only, never from the URL.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $credential = $request->json('credential');
        $panel = $this->filament->getCurrentPanel();

        try {
            if (! $panel instanceof Panel) {
                throw new AuthenticationException;
            }

            $this->signInWithFirebaseCredential->handle(is_string($credential) ? $credential : '', $panel);
        } catch (AuthenticationException) {
            return new JsonResponse(['message' => __('filament-panels::auth/pages/login.messages.failed')], 422);
        }

        $request->session()->regenerate();

        return new JsonResponse(['redirect' => redirect()->intended($panel->getUrl())->getTargetUrl()]);
    }
}
