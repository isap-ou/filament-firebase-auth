<?php

return [

    'section' => [
        'label' => 'Sessions and account',
    ],

    'actions' => [

        'logout_other_sessions' => [
            'label' => 'Log out other sessions',
            'modal_description' => 'All other browsers and devices that are signed in to this panel will be logged out. This session stays signed in.',
            'notifications' => [
                'done' => 'Other sessions are logged out.',
            ],
        ],

        'logout_everywhere' => [
            'label' => 'Log out everywhere',
            'modal_description' => 'All other sessions will be logged out. This also signs you out of other apps on the same Firebase project. This session stays signed in.',
            'notifications' => [
                'done' => 'You are logged out everywhere else.',
            ],
        ],

        'delete_account' => [
            'label' => 'Delete account',
            'modal_description' => 'Your account will be deleted. You cannot undo this.',
        ],

    ],

    'messages' => [
        'email_exists' => 'This email already has an account.',
        'firebase_failed' => 'The change could not be saved. Try again later.',
        'firebase_unavailable' => 'The password could not be checked. Try again later.',
        'no_link' => 'This account has no sign-in account to update.',
    ],

];
