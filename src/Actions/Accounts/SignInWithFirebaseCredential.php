<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Actions\Identity\PersistResolvedUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\ResolveLocalUser;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
use Isapp\FilamentFirebaseAuth\Enums\DeleteAccounts;
use Isapp\FilamentFirebaseAuth\Enums\DeletionReason;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;
use Isapp\FilamentFirebaseAuth\FilamentFirebaseAuthPlugin;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use LogicException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Signs in to a panel with a social provider credential.
 *
 * Social sign-in trusts the provider, so it does not run Filament's multi-factor challenge.
 */
class SignInWithFirebaseCredential
{
    public function __construct(
        protected SocialSignInMethod $socialSignInMethod,
        protected ResolveLocalUser $resolveLocalUser,
        protected PersistResolvedUser $persistResolvedUser,
        protected DeleteFirebaseUserIfAllowed $deleteFirebaseUserIfAllowed,
        protected Repository $config,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Proves the Firebase user, resolves and saves the local record, checks `canAccessPanel()`
     * and logs in with the panel guard.
     *
     * The credential must come from one of the panel providers. A panel without providers fails.
     * A closed panel blocks a Firebase user without a record. Then the deletion policy runs.
     *
     * @throws AuthenticationException on any failure. The exception has no detail.
     */
    public function handle(#[SensitiveParameter] string $credential, Panel $panel): Authenticatable
    {
        $guard = $panel->auth();
        $provider = $guard instanceof SessionGuard ? $guard->getProvider() : null;

        $plugin = $this->plugin($panel);

        // The plugin adds no route to a panel without providers. This check is a second line of defense.
        if (! $guard instanceof SessionGuard || ! $provider instanceof EloquentUserProvider || ! $plugin instanceof FilamentFirebaseAuthPlugin || $plugin->getProviders() === []) {
            throw new AuthenticationException;
        }

        try {
            $user = $this->resolveAndPersist($credential, $panel, $plugin, $provider->getModel());
        } catch (FirebaseException $exception) {
            // A transport message can contain the request URL with the API key, so the log names the class only.
            $this->logger->error('Firebase failed during a social sign-in.', ['exception' => $exception::class]);

            throw new AuthenticationException;
        } catch (QueryException | LogicException $exception) {
            // A driver message or a message from host code can contain personal data.
            $this->logger->error('The local record could not be saved after a social sign-in.', [
                'exception' => $exception::class,
            ]);

            throw new AuthenticationException;
        }

        if ($user instanceof FilamentUser && ! $user->canAccessPanel($panel)) {
            throw new AuthenticationException;
        }

        $guard->login($user);

        return $user;
    }

    /**
     * @param  class-string<Model&Authenticatable>  $model
     */
    protected function resolveAndPersist(#[SensitiveParameter] string $credential, Panel $panel, FilamentFirebaseAuthPlugin $plugin, string $model): Model & Authenticatable
    {
        $firebaseUser = $this->socialSignInMethod->verify($credential, $plugin->getProviders());
        $createOnSignIn = $plugin->shouldCreateOnSignIn();
        $resolved = $this->resolveLocalUser->handle($firebaseUser, $model, $createOnSignIn);

        if ($resolved->resolution === Resolution::Blocked) {
            if (! $createOnSignIn) {
                $this->deleteIfBlockedOnClosedPanel($firebaseUser, $model);
            }

            throw new AuthenticationException;
        }

        return $this->persistResolvedUser->handle($resolved, $firebaseUser, $panel);
    }

    /**
     * Runs the deletion policy only when the closed panel is the only reason for the block:
     * an open panel would create a record for this Firebase user.
     *
     * @param  class-string<Model&Authenticatable>  $model
     */
    protected function deleteIfBlockedOnClosedPanel(UserRecord $firebaseUser, string $model): void
    {
        if (DeleteAccounts::from($this->config->string('filament-firebase-auth.delete_accounts')) === DeleteAccounts::Never) {
            return;
        }

        if ($this->resolveLocalUser->handle($firebaseUser, $model, true)->resolution !== Resolution::New) {
            return;
        }

        $this->deleteFirebaseUserIfAllowed->handle($firebaseUser, DeletionReason::BlockedOnClosedPanel);
    }

    protected function plugin(Panel $panel): ?FilamentFirebaseAuthPlugin
    {
        $plugin = $panel->hasPlugin('filament-firebase-auth') ? $panel->getPlugin('filament-firebase-auth') : null;

        return $plugin instanceof FilamentFirebaseAuthPlugin ? $plugin : null;
    }
}
