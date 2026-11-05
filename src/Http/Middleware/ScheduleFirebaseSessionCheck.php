<?php

namespace Isapp\FilamentFirebaseAuth\Http\Middleware;

use Closure;
use Filament\FilamentManager;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Isapp\FilamentFirebaseAuth\Jobs\SyncFirebaseIdentity;
use Isapp\FilamentFirebaseAuth\Models\FirebaseIdentity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts a background check of the Firebase user of the session after each interval.
 *
 * The middleware never calls Firebase. The job updates the link, and Laravel's
 * `AuthenticateSession` logs the session out on the next request when the fingerprint changed.
 */
class ScheduleFirebaseSessionCheck
{
    public function __construct(
        protected FilamentManager $filament,
        protected Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A host config can read the value from `env()`, so it can be a string. Null means the default.
        $interval = (int) ($this->config->get('filament-firebase-auth.revalidate_every') ?? 15);

        if ($interval <= 0 || ! $request->hasSession()) {
            return $next($request);
        }

        $identity = $this->identity();

        if (! $identity instanceof FirebaseIdentity) {
            return $next($request);
        }

        $session = $request->session();
        $key = 'filament-firebase-auth.checked_at.' . $this->filament->getAuthGuard();
        $checkedAt = $session->get($key);

        if (is_int($checkedAt) && $checkedAt > now()->subMinutes($interval)->getTimestamp()) {
            return $next($request);
        }

        $session->put($key, now()->getTimestamp());

        $this->dispatch(new SyncFirebaseIdentity($identity->getKey()));

        return $next($request);
    }

    /**
     * Returns the link of the signed-in user, or null for a guest or a record without a link.
     * The fingerprint check of `AuthenticateSession` has loaded the link already.
     */
    protected function identity(): ?FirebaseIdentity
    {
        $user = $this->filament->auth()->user();

        if (! $user instanceof Model) {
            return null;
        }

        $identity = $user->getRelationValue('firebaseIdentity');

        return $identity instanceof FirebaseIdentity ? $identity : null;
    }

    /**
     * Without a queue, the job runs in this process after the response is sent.
     */
    protected function dispatch(SyncFirebaseIdentity $job): void
    {
        $queue = $this->config->get('filament-firebase-auth.revalidate_queue');

        if ($queue === null) {
            dispatch($job)->afterResponse();

            return;
        }

        dispatch($job)->onQueue((string) $queue);
    }
}
