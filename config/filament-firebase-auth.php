<?php

return [

    /*
     * The table that links local records to Firebase users.
     */
    'table' => 'firebase_identities',

    /*
     * When the plugin deletes a Firebase account that no local record can use:
     * "never", "new_only" or "orphaned".
     */
    'delete_accounts' => 'never',

    /*
     * For "new_only": a Firebase account younger than this number of seconds counts as new.
     */
    'new_account_window' => 60,

    /*
     * The plugin checks the Firebase user of a session again after this number of minutes.
     * Set 0 to turn the check off.
     */
    'revalidate_every' => 15,

    /*
     * How the login page signs in with a social provider. "js_sdk" is the only value.
     */
    'social_sign_in' => 'js_sdk',

    /*
     * The Firebase web config for the JavaScript SDK on the login page.
     * The server credentials stay in the kreait/laravel-firebase config.
     */
    'web' => [
        'api_key' => env('FIREBASE_WEB_API_KEY'),
        'auth_domain' => env('FIREBASE_WEB_AUTH_DOMAIN'),
        'project_id' => env('FIREBASE_WEB_PROJECT_ID'),
        'app_id' => env('FIREBASE_WEB_APP_ID'),
    ],

];
