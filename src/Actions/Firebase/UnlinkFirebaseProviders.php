<?php

namespace Isapp\FilamentFirebaseAuth\Actions\Firebase;

use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;

/**
 * Removes the sign-in providers of a Firebase user, except the kept ones.
 *
 * The providers are shared with other apps on the same Firebase project.
 */
class UnlinkFirebaseProviders
{
    public function __construct(protected Auth $auth) {}

    /**
     * Reads the Firebase user again, so that a provider linked a moment ago is removed too.
     * Without a provider to remove, it makes no second call. Returns the Firebase user after the change.
     * Firebase exceptions go to the caller.
     *
     * @param  list<string>  $keep
     */
    public function handle(string $uid, array $keep = ['password']): UserRecord
    {
        $firebaseUser = $this->auth->getUser($uid);

        $providers = [];

        foreach ($firebaseUser->providerData as $provider) {
            if (! in_array($provider->providerId, $keep, true)) {
                $providers[] = $provider->providerId;
            }
        }

        if ($providers === []) {
            return $firebaseUser;
        }

        return $this->auth->unlinkProvider($uid, $providers);
    }
}
