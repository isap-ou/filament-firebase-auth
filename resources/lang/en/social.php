<?php

return [

    'providers' => [
        'google' => 'Google',
        'apple' => 'Apple',
        'microsoft' => 'Microsoft',
        'github' => 'GitHub',
        'facebook' => 'Facebook',
        'twitter' => 'X (Twitter)',
        'yahoo' => 'Yahoo',
    ],

    'divider' => 'or',

    'continue_with' => 'Continue with :provider',

    'errors' => [
        'popup_closed' => 'The sign-in window was closed before the sign-in was complete.',
        'popup_blocked' => 'The browser blocked the sign-in window. Allow pop-ups for this site and try again.',
        'account_exists' => 'This email already has an account with another sign-in method. Sign in with that method.',
        'generic' => 'The sign-in failed. Try again.',
    ],

];
