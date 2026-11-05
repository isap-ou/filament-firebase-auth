<?php

namespace Isapp\FilamentFirebaseAuth\Actions;

use Kreait\Firebase\Auth\UserRecord;
use SensitiveParameter;

/**
 * Returns the Firebase account with an email, or creates it.
 *
 * A host app can use it with LinkFirebaseIdentity to give a record a Firebase account.
 */
class FindOrCreateFirebaseUser
{
    public function __construct(
        protected FindFirebaseUserByEmail $findFirebaseUserByEmail,
        protected CreateFirebaseUser $createFirebaseUser,
    ) {}

    /**
     * An existing account keeps its password, its name and its verification.
     *
     * @return array{0: UserRecord, 1: bool} The account, and true when this call created it.
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $name, bool $verified): array
    {
        $user = $this->findFirebaseUserByEmail->handle($email);

        if ($user !== null) {
            return [$user, false];
        }

        return [$this->createFirebaseUser->handle($email, $password, $name, $verified), true];
    }
}
