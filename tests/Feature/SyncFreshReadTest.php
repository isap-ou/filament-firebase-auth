<?php

use Isapp\FilamentFirebaseAuth\Actions\Firebase\FindFirebaseUser;

require_once __DIR__ . '/../Support/SessionCheckFixtures.php';

it('reads firebase again in the session check job after a change in the same request', function () {
    // Without a queue, the job runs in the request process after the response. Earlier code in
    // that request can have read the user through the scoped `FindFirebaseUser`.
    $user = linkedUser(email: 'old@example.com');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->with('uid-1')->twice()->andReturn(
        userRecord(['email' => 'old@example.com']),
        userRecord(['email' => 'new@example.com']),
    );

    expect(app(FindFirebaseUser::class)->handle('uid-1')?->email)->toBe('old@example.com');

    // Firebase now has the new email, for example after a save on the profile page.
    sessionCheckRunJob($user);

    expect($user->refresh()->getAttribute('email'))->toBe('new@example.com');
});
