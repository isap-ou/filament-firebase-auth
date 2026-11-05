<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Actions\Identity\PersistResolvedUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\ResolveLocalUser;
use Isapp\FilamentFirebaseAuth\Contracts\SocialSignInMethod;
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
        protected LoggerInterface $logger,
    ) {}

    /**
     * Proves the Firebase user, resolves and saves the local record, checks `canAccessPanel()`
     * and logs in with the panel guard.
     *
     * A closed panel blocks a Firebase user without a record. Then the deletion policy runs.
     *
     * @throws AuthenticationException on any failure. The exception has no detail.
     */
    public function handle(#[SensitiveParameter] string $credential, Panel $panel): Authenticatable
    {
        $guard = $panel->auth();
        $provider = $guard instanceof SessionGuard ? $guard->getProvider() : null;

        if (! $guard instanceof SessionGuard || ! $provider instanceof EloquentUserProvider) {
            throw new AuthenticationException;
        }

        try {
            $user = $this->resolveAndPersist($credential, $panel, $provider->getModel());
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
    protected function resolveAndPersist(#[SensitiveParameter] string $credential, Panel $panel, string $model): Model & Authenticatable
    {
        $firebaseUser = $this->socialSignInMethod->verify($credential);
        $createOnSignIn = $this->shouldCreateOnSignIn($panel);
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
        if ($this->resolveLocalUser->handle($firebaseUser, $model, true)->resolution !== Resolution::New) {
            return;
        }

        $this->deleteFirebaseUserIfAllowed->handle($firebaseUser, DeletionReason::BlockedOnClosedPanel);
    }

    protected function shouldCreateOnSignIn(Panel $panel): bool
    {
        if (! $panel->hasPlugin('filament-firebase-auth')) {
            return false;
        }

        $plugin = $panel->getPlugin('filament-firebase-auth');

        return $plugin instanceof FilamentFirebaseAuthPlugin && $plugin->shouldCreateOnSignIn();
    }
}
