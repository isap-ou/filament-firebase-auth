<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;

/**
 * Tells if a Firebase user can sign in with an email and a password.
 */
class DetectPasswordProvider
{
    public function handle(UserRecord $firebaseUser): bool
    {
        foreach ($firebaseUser->providerData as $provider) {
            if ($provider->providerId === 'password') {
                return true;
            }
        }

        return false;
    }
}
