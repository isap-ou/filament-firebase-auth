<?php

namespace Isapp\FilamentFirebaseAuth\SocialSignIn;

use DateTimeInterface;
use Filament\Panel;
use Filament\Support\Assets\AssetManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Kreait\Firebase\Exception\FirebaseException;
use Lcobucci\JWT\Token\DataSet;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Social sign-in with the Firebase JS SDK (config value `js_sdk`).
 *
 * The browser signs in with `signInWithPopup()` and posts the Firebase ID token to the plugin route.
 * The server checks the token with `verifyIdToken()` and reads the Firebase user.
 */
class JsSdkSignIn implements SocialSignInMethod
{
    /**
     * The Firebase server client is resolved only in `verify()`, so that the login page renders
     * the buttons without building it.
     */
    public function __construct(
        protected Container $container,
        protected Repository $config,
        protected ViewFactory $view,
        protected AssetManager $assets,
        protected LoggerInterface $logger,
    ) {}

    /**
     * @param  array<SocialProvider>  $providers
     */
    public function renderButtons(Panel $panel, array $providers): Htmlable
    {
        $plugin = $panel->hasPlugin('filament-firebase-auth') ? $panel->getPlugin('filament-firebase-auth') : null;
        $plugin = $plugin instanceof FilamentFirebaseAuthPlugin ? $plugin : null;

        /** @var view-string $view */
        $view = 'filament-firebase-auth::components.social-buttons';

        return new HtmlString($this->view->make($view, [
            'scriptSrc' => $this->assets->getScriptSrc('filament-firebase-auth', 'isapp/filament-firebase-auth'),
            'options' => [
                'config' => [
                    'apiKey' => $this->config->get('filament-firebase-auth.web.api_key'),
                    'authDomain' => $this->config->get('filament-firebase-auth.web.auth_domain'),
                    'projectId' => $this->config->get('filament-firebase-auth.web.project_id'),
                    'appId' => $this->config->get('filament-firebase-auth.web.app_id'),
                ],
                'endpoint' => $panel->route('firebase.sign-in'),
                'providers' => array_map(fn (SocialProvider $provider): array => [
                    'id' => $provider->value,
                    'sdkClass' => $provider->getSdkClass(),
                    'scopes' => $plugin?->getProviderScopes($provider) ?? [],
                    // An object, so that an empty list is `{}` in JSON.
                    'parameters' => (object) ($plugin?->getProviderParameters($provider) ?? []),
                ], $providers),
                'messages' => [
                    'popupClosed' => __('filament-firebase-auth::social.errors.popup_closed'),
                    'popupBlocked' => __('filament-firebase-auth::social.errors.popup_blocked'),
                    'accountExists' => __('filament-firebase-auth::social.errors.account_exists'),
                    'generic' => __('filament-firebase-auth::social.errors.generic'),
                    'sessionExpired' => __('filament-firebase-auth::social.errors.session_expired'),
                ],
            ],
            'buttons' => array_map(fn (SocialProvider $provider): array => [
                'id' => $provider->value,
                'label' => __('filament-firebase-auth::social.continue_with', ['provider' => $provider->getLabel()]),
                // The SVG files ship with the plugin, so they are safe to print as HTML.
                'icon' => new HtmlString((string) file_get_contents($provider->getIconPath())),
            ], $providers),
        ])->render());
    }

    /**
     * Checks the ID token with `checkIfRevoked: true`. Then the token must come from one of the
     * given providers (claim `firebase.sign_in_provider`) and from a sign-in of the last few minutes
     * (claim `auth_time`). Then it reads the Firebase user of the `sub` claim.
     *
     * A disabled Firebase user fails. Every failure throws the same exception without detail.
     *
     * @param  array<SocialProvider>  $providers
     */
    public function verify(#[SensitiveParameter] string $credential, array $providers): UserRecord
    {
        if ($credential === '' || $providers === []) {
            throw new AuthenticationException;
        }

        $claims = $this->verifiedClaims($credential);
        $uid = $claims->get('sub');

        if (! $this->isAllowedProvider($claims, $providers) || ! $this->isRecentSignIn($claims) || ! is_string($uid) || $uid === '') {
            throw new AuthenticationException;
        }

        try {
            $firebaseUser = $this->container->make(FindFirebaseUser::class)->handle($uid);
        } catch (FirebaseException $exception) {
            throw $this->firebaseFailure($exception);
        }

        if (! $firebaseUser instanceof UserRecord || $firebaseUser->disabled) {
            throw new AuthenticationException;
        }

        return $firebaseUser;
    }

    /**
     * The oldest sign-in, in seconds, that a token may prove. The JS posts the token right after
     * the popup, so a real sign-in is always younger. A token kept by another app is not.
     */
    protected function maxSignInAge(): int
    {
        return 300;
    }

    protected function verifiedClaims(#[SensitiveParameter] string $credential): DataSet
    {
        try {
            return $this->container->make(Auth::class)->verifyIdToken($credential, checkIfRevoked: true)->claims();
        } catch (FailedToVerifyToken | RevokedIdToken | InvalidArgumentException $exception) {
            // The log names the exception class only. It never contains the token.
            $this->logger->info('Firebase rejected an ID token.', ['exception' => $exception::class]);

            throw new AuthenticationException;
        } catch (FirebaseException $exception) {
            throw $this->firebaseFailure($exception);
        }
    }

    /**
     * A password, phone, anonymous or custom token, or a provider that the panel does not list, fails.
     *
     * @param  array<SocialProvider>  $providers
     */
    protected function isAllowedProvider(DataSet $claims, array $providers): bool
    {
        $firebase = $claims->get('firebase');
        $signInProvider = is_array($firebase) ? ($firebase['sign_in_provider'] ?? null) : null;
        $allowed = array_map(fn (SocialProvider $provider): string => $provider->value, $providers);

        if (is_string($signInProvider) && in_array($signInProvider, $allowed, true)) {
            return true;
        }

        // The provider ID is not personal data.
        $this->logger->info('The ID token comes from a sign-in provider that the panel does not allow.', [
            'sign_in_provider' => is_string($signInProvider) ? $signInProvider : null,
        ]);

        return false;
    }

    protected function isRecentSignIn(DataSet $claims): bool
    {
        $authTime = $claims->get('auth_time');
        $authTime = $authTime instanceof DateTimeInterface ? $authTime->getTimestamp() : $authTime;

        if (is_int($authTime) && now()->getTimestamp() - $authTime <= $this->maxSignInAge()) {
            return true;
        }

        $this->logger->info('The ID token proves a sign-in that is too old.');

        return false;
    }

    /**
     * A transport message can contain the request URL with the API key, so the log names the class only.
     */
    protected function firebaseFailure(FirebaseException $exception): AuthenticationException
    {
        $this->logger->error('Firebase failed during a social sign-in.', ['exception' => $exception::class]);

        return new AuthenticationException;
    }
}
