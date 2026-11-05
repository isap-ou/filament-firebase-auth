<?php

use Illuminate\Auth\SessionGuard;
use Isapp\FilamentFirebaseAuth\Jobs\SyncFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models\User;

/**
 * Returns the session guard of a fixture panel.
 */
function sessionCheckGuard(string $guard = 'app'): SessionGuard
{
    $sessionGuard = auth()->guard($guard);

    if (! $sessionGuard instanceof SessionGuard) {
        throw new RuntimeException("The guard [{$guard}] is not a session guard.");
    }

    return $sessionGuard;
}

/**
 * Makes the app forget the signed-in user. The next test request then reads the user
 * and its link from the session or the cookie again, as a new process does.
 */
function sessionCheckNextRequest(): void
{
    app('auth')->forgetGuards();
}

/**
 * Runs the session check job for the link of a record, the way a queue worker runs it.
 */
function sessionCheckRunJob(User $user): void
{
    $identityId = $user->firebaseIdentity?->getKey() ?? throw new RuntimeException('The record has no link.');

    app()->call([new SyncFirebaseIdentity($identityId), 'handle']);
}
