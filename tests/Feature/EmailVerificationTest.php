<?php

use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

it('verifies the email in firebase from the filament link', function () {
    $user = linkedUser(verified: false);
    $firebase = mockFirebase();
    $firebase->shouldReceive('updateUser')->once()->with('uid-1', ['emailVerified' => true])->andReturn(userRecord());
    Filament::setCurrentPanel('app');

    $url = Filament::getVerifyEmailUrl($user);

    actingAs($user, 'app')->get($url)->assertRedirect();

    expect($user->firebaseIdentity?->fresh()?->email_verified_at)->not->toBeNull();
});

it('skips the prompt for a user verified in another project', function () {
    // The link copies the shared Firebase flag, for example after a verification in another app.
    $user = linkedUser(verified: true);
    $firebase = mockFirebase();
    $firebase->shouldNotReceive('getUser');

    actingAs($user, 'app')->get('/app')->assertOk();
});

it('shows the prompt for a user without a verified email', function () {
    $user = linkedUser(verified: false);
    mockFirebase();

    actingAs($user, 'app')->get('/app')->assertRedirect(Filament::getPanel('app')->getEmailVerificationPromptUrl());
});
