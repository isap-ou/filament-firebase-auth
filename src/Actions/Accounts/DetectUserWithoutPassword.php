<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\DetectPasswordProvider;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use Psr\Log\LoggerInterface;

/**
 * Tells if a local user signs in without a password, for example with Google only.
 * Filament's multi-factor authentication does not apply to such a user: the social
 * provider owns the second factor.
 */
class DetectUserWithoutPassword
{
    public function __construct(
        protected FindFirebaseUser $findFirebaseUser,
        protected DetectPasswordProvider $detectPasswordProvider,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Returns true only when the user is linked, Firebase has the linked user, and that
     * Firebase user has no `password` provider.
     *
     * Every other case returns false, so Filament's rules apply (fail closed): a guest, a model
     * without the plugin trait, a record without a link, a UID that Firebase does not know,
     * and a Firebase failure. The plugin logs a Firebase failure with the UID and the exception class only.
     */
    public function handle(?Authenticatable $user): bool
    {
        if (! $user instanceof Authenticatable || ! method_exists($user, 'getFirebaseUid')) {
            return false;
        }

        $uid = $user->getFirebaseUid();

        if (! is_string($uid) || $uid === '') {
            return false;
        }

        try {
            $firebaseUser = $this->findFirebaseUser->handle($uid);
        } catch (FirebaseException $exception) {
            // A transport message can contain the request URL with the API key, so the log names the class only.
            $this->logger->warning('Firebase could not tell the sign-in providers of a user.', [
                'uid' => $uid,
                'exception' => $exception::class,
            ]);

            return false;
        }

        return $firebaseUser instanceof UserRecord && ! $this->detectPasswordProvider->handle($firebaseUser);
    }
}
