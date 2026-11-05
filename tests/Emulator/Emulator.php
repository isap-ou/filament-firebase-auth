<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Emulator;

use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Auth\SignIn\FailedToSignIn;
use Kreait\Firebase\Contract\Auth;

/**
 * Talks to the Firebase Auth Emulator for the `emulator` test group.
 *
 * `firebase emulators:exec` sets `FIREBASE_AUTH_EMULATOR_HOST`. The real `kreait` client reads it too.
 */
class Emulator
{
    public function __construct(protected Application $app) {}

    /**
     * The project ID of the emulator. The `demo-` prefix tells the emulator that no real project exists.
     * It must match the `--project` option of `emulators:exec`.
     */
    public function projectId(): string
    {
        return 'demo-filament-firebase-auth';
    }

    /**
     * Returns null when the emulator does not run.
     */
    public function host(): ?string
    {
        $host = getenv('FIREBASE_AUTH_EMULATOR_HOST');

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The real `kreait` client of the app, pointed at the emulator.
     */
    public function firebase(): Auth
    {
        return $this->app->make(Auth::class);
    }

    /**
     * Asks the emulator directly whether the password signs in. The plugin is not involved.
     */
    public function acceptsPassword(string $email, string $password): bool
    {
        try {
            $this->firebase()->signInWithEmailAndPassword($email, $password);
        } catch (FailedToSignIn) {
            return false;
        }

        return true;
    }

    /**
     * Deletes all accounts of the emulator project, so that each test starts empty.
     */
    public function clearAccounts(): void
    {
        $this->request()->delete("/emulator/v1/projects/{$this->projectId()}/accounts")->throw();
    }

    /**
     * Creates an account through the public sign-up endpoint, as the Firebase JS SDK does
     * for a new user. The emulator accepts any API key. Returns the UID.
     */
    public function signUpThroughRest(string $email, string $password): string
    {
        $response = $this->request()
            ->post('/identitytoolkit.googleapis.com/v1/accounts:signUp?key=emulator-api-key', [
                'email' => $email,
                'password' => $password,
                'returnSecureToken' => true,
            ])
            ->throw();

        return (string) $response->json('localId');
    }

    /**
     * Moves the creation time of an account. Only an admin request may set `createdAt`.
     */
    public function setCreatedAt(string $uid, DateTimeInterface $createdAt): void
    {
        $this->request()
            ->withToken('owner')
            ->post("/identitytoolkit.googleapis.com/v1/projects/{$this->projectId()}/accounts:update", [
                'localId' => $uid,
                'createdAt' => (string) ($createdAt->getTimestamp() * 1000),
            ])
            ->throw();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl("http://{$this->host()}")->acceptJson()->timeout(10);
    }
}
