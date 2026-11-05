<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Emulator;

use Isapp\FilamentFirebaseAuth\Tests\TestCase;

/**
 * Runs the plugin against the Firebase Auth Emulator, with the real `kreait` client.
 *
 * `firebase emulators:exec` sets `FIREBASE_AUTH_EMULATOR_HOST`. Without it, every test skips.
 * The emulator needs no credentials: `kreait` sends `Bearer owner` to it.
 */
class EmulatorTestCase extends TestCase
{
    protected function setUp(): void
    {
        // The app does not exist yet, so the check reads the variable itself, as `Emulator::host()` does.
        $host = getenv('FIREBASE_AUTH_EMULATOR_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('FIREBASE_AUTH_EMULATOR_HOST is not set. Run the tests through `firebase emulators:exec`.');
        }

        parent::setUp();

        $this->app->make(Emulator::class)->clearAccounts();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $projectId = $app->make(Emulator::class)->projectId();

        // The emulator ignores credentials. This placeholder gives `kreait` the project ID. Without it,
        // `kreait` looks for Application Default Credentials: a slow probe of the cloud metadata server,
        // or the real credentials of the developer machine. The private key is never used.
        $app['config']->set('firebase.projects.app.credentials', [
            'type' => 'service_account',
            'project_id' => $projectId,
            'client_email' => "emulator@{$projectId}.iam.gserviceaccount.com",
            'private_key' => 'unused',
        ]);
        $app['config']->set('firebase.projects.app.cache_store', 'array');
    }
}
