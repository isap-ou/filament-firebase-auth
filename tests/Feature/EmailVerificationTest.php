<?php

use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;

// The background session check calls Firebase after a panel request. These tests mock Firebase strictly,
// so they turn the check off. SessionRevalidationTest covers the check.
beforeEach(function () {
    config()->set('filament-firebase-auth.revalidate_every', 0);
});

it('verifies the email in firebase from the filament link', function () {
    $user = linkedUser(verified: false);
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')->andReturn(userRecord(['emailVerified' => false]));
    $firebase->shouldReceive('updateUser')->once()
        ->with('uid-1', ['email' => 'jane@example.com', 'emailVerified' => true])->andReturn(userRecord());
    Filament::setCurrentPanel('app');

    $url = Filament::getVerifyEmailUrl($user);

    actingAs($user, 'app')->get($url)->assertRedirect();

    expect($user->firebaseIdentity?->fresh()?->email_verified_at)->not->toBeNull();
});

it('does not verify a firebase email that the link did not prove', function () {
    // The attacker keeps a link for their own address, then changes the Firebase email of their account.
    $user = linkedUser(verified: false, email: 'evil@example.com');
    $firebase = mockFirebase();
    $firebase->shouldReceive('getUser')->once()->with('uid-1')
        ->andReturn(userRecord(['email' => 'boss@example.com', 'emailVerified' => false]));
    $firebase->shouldNotReceive('updateUser');
    Filament::setCurrentPanel('app');

    $url = Filament::getVerifyEmailUrl($user);

    actingAs($user, 'app')->get($url);

    expect($user->firebaseIdentity?->fresh()?->email_verified_at)->toBeNull();
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
