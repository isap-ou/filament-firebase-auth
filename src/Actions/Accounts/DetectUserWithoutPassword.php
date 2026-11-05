<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Kreait\Firebase\Exception\FirebaseException;
use Psr\Log\LoggerInterface;

/**
 * Tells if a local user signs in without a password, for example with Google only.
 * Filament's multi-factor authentication does not apply to such a user: the social
 * provider owns the second factor.
 */
class DetectUserWithoutPassword
{
    public function __construct(protected LoggerInterface $logger) {}

    /**
     * Returns true only when Firebase says that the user has no `password` provider.
     *
     * A guest or a model without the plugin trait returns false. When Firebase cannot answer,
     * the plugin logs the exception class and returns false, so Filament's rules apply (fail closed).
     */
    public function handle(?Authenticatable $user): bool
    {
        if (! $user instanceof Authenticatable || ! method_exists($user, 'hasFirebasePasswordProvider')) {
            return false;
        }

        try {
            return ! $user->hasFirebasePasswordProvider();
        } catch (FirebaseException $exception) {
            // A transport message can contain the request URL with the API key, so the log names the class only.
            $this->logger->warning('Firebase could not tell the sign-in providers of a user.', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
