<?php

namespace Isapp\FilamentFirebaseAuth;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Isapp\FilamentFirebaseAuth\Actions\Panels\EnsurePanelUsesFirebasePages;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
use Isapp\FilamentFirebaseAuth\Enums\SocialProvider;
use Isapp\FilamentFirebaseAuth\Http\Controllers\FirebaseSignInController;

/**
 * The per-panel settings of the plugin.
 */
class FilamentFirebaseAuthPlugin implements Plugin
{
    use EvaluatesClosures;

    protected bool | Closure $createOnSignIn = true;

    /**
     * @var list<SocialProvider>
     */
    protected array $providers = [];

    /**
     * OAuth scopes by Firebase provider ID.
     *
     * @var array<string, list<string>>
     */
    protected array $providerScopes = [];

    /**
     * Custom OAuth parameters by Firebase provider ID.
     *
     * @var array<string, array<string, string>>
     */
    protected array $providerParameters = [];

    /**
     * Filament's plugin docs use a static `make()`. The `Plugin` contract does not require it.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-firebase-auth';
    }

    public function register(Panel $panel): void
    {
        $this->registerSocialSignIn($panel);
    }

    /**
     * Filament calls this method on the first request of the panel.
     * A Firebase panel with Filament's own registration or profile page then fails at once.
     */
    public function boot(Panel $panel): void
    {
        app(EnsurePanelUsesFirebasePages::class)->handle($panel);
    }

    /**
     * When true, a sign-in with a Firebase account that has no local record creates one.
     * When false, the panel is closed: only linked or linkable records sign in.
     */
    public function createOnSignIn(bool | Closure $condition = true): static
    {
        $this->createOnSignIn = $condition;

        return $this;
    }

    public function shouldCreateOnSignIn(): bool
    {
        return (bool) $this->evaluate($this->createOnSignIn);
    }

    /**
     * The social providers that get a button on the login and registration pages, in this order.
     *
     * @param  array<SocialProvider>  $providers
     */
    public function providers(array $providers): static
    {
        $unique = [];

        // The typed closure rejects a value that is not a `SocialProvider`, for example a provider ID string.
        foreach (array_map(fn (SocialProvider $provider): SocialProvider => $provider, $providers) as $provider) {
            $unique[$provider->value] = $provider;
        }

        $this->providers = array_values($unique);

        return $this;
    }

    /**
     * @return list<SocialProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * The OAuth scopes that the provider asks for, for example `mail.read` for Microsoft.
     * They add to the default scopes of the provider (`SocialProvider::getDefaultScopes()`).
     *
     * @param  array<string>  $scopes
     */
    public function providerScopes(SocialProvider $provider, array $scopes): static
    {
        $this->providerScopes[$provider->value] = array_values($scopes);

        return $this;
    }

    /**
     * The default scopes of the provider, then the scopes from `providerScopes()`, without repeats.
     *
     * @return list<string>
     */
    public function getProviderScopes(SocialProvider $provider): array
    {
        return array_values(array_unique([
            ...$provider->getDefaultScopes(),
            ...$this->providerScopes[$provider->value] ?? [],
        ]));
    }

    /**
     * Custom OAuth parameters for the provider, for example `['tenant' => '...']` for Microsoft.
     *
     * @param  array<string, string>  $parameters
     */
    public function providerParameters(SocialProvider $provider, array $parameters): static
    {
        $this->providerParameters[$provider->value] = $parameters;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getProviderParameters(SocialProvider $provider): array
    {
        return $this->providerParameters[$provider->value] ?? [];
    }

    /**
     * Adds the sign-in route to the panel route group, so it has the panel middleware.
     * Adds the buttons to Filament's own login and registration pages.
     *
     * A panel without providers gets no route and no buttons. Both closures read the providers
     * when Filament calls them, so the order of the plugin settings does not matter.
     */
    private function registerSocialSignIn(Panel $panel): void
    {
        $panel->routes(function (): void {
            if ($this->getProviders() === []) {
                return;
            }

            Route::post('firebase/sign-in', FirebaseSignInController::class)
                ->middleware('throttle:filament-firebase-auth')
                ->name('firebase.sign-in');
        });

        $buttons = fn (): Htmlable => $this->getProviders() === []
            ? new HtmlString('')
            : app(SocialSignInMethod::class)->renderButtons($panel, $this->getProviders());

        $panel->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, $buttons);
        $panel->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, $buttons);
    }
}
