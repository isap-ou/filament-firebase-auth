<?php

namespace Isapp\FilamentFirebaseAuth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Isapp\FilamentFirebaseAuth\Actions\Firebase\FetchFirebaseUser;
use Isapp\FilamentFirebaseAuth\Actions\Identity\RefreshFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Contracts\SyncsLocalUsers;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\FirebaseException;
use Psr\Log\LoggerInterface;

/**
 * Checks the Firebase user of one link and copies its state to the link.
 *
 * The job runs outside a panel request. It finds the record through the link, not through a panel.
 * A changed link changes the session fingerprint (`HasFirebaseIdentity::getAuthPassword()`).
 * Laravel's `AuthenticateSession` then logs the session out on its next request.
 *
 * Without a queue, the job runs in the request process after the response. The scoped
 * `FindFirebaseUser` can then hold the record from before a change in that request.
 * The job therefore reads Firebase with `FetchFirebaseUser`, which never remembers a record.
 */
class SyncFirebaseIdentity implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int | string $identityId) {}

    public function handle(FetchFirebaseUser $fetch, SyncsLocalUsers $sync, RefreshFirebaseIdentity $refresh): void
    {
        $identity = FirebaseIdentity::query()->find($this->identityId);

        if (! $identity instanceof FirebaseIdentity) {
            return;
        }

        try {
            $firebaseUser = $fetch->handle($identity->firebase_uid);
        } catch (FirebaseException $exception) {
            $this->logFirebaseFailure($identity, $exception);

            return;
        }

        if (! $firebaseUser instanceof UserRecord) {
            // Firebase has no user to copy, so the job writes the mark itself.
            // An earlier mark stays, so the fingerprint does not change on each check.
            $identity->forceFill(['invalidated_at' => $identity->invalidated_at ?? now()])->save();

            return;
        }

        if ($firebaseUser->disabled) {
            // The record is not synced. RefreshFirebaseIdentity sets the mark of a disabled user.
            $refresh->handle($identity, $firebaseUser);

            return;
        }

        // The link state goes first. A host sync class or a failed record save can then never keep a revoked session alive.
        $refresh->handle($identity, $firebaseUser);

        $record = $identity->getRelationValue('authenticatable');

        if (! $record instanceof Model || ! $record instanceof Authenticatable) {
            return;
        }

        try {
            $sync->handle($record, $firebaseUser);
        } catch (QueryException $exception) {
            // For example, another app gave the Firebase account an email that another local record holds.
            app(LoggerInterface::class)->warning('The session check could not save the local record. The link is up to date.', [
                'uid' => $identity->firebase_uid,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Logs the UID and the exception class only. A transport message can contain the request URL with the API key.
     */
    protected function logFirebaseFailure(FirebaseIdentity $identity, FirebaseException $exception): void
    {
        app(LoggerInterface::class)->warning('The Firebase session check failed. The link did not change.', [
            'uid' => $identity->firebase_uid,
            'exception' => $exception::class,
        ]);
    }
}
