<?php

return [
    'existing_account' => 'A Firebase account with the email :email already exists. The command links it and does not change its password. The local name comes from the Firebase account, not from the name option.',
    'unverified_account' => 'A Firebase account with the email :email already exists, but its email is not verified. The command did not create a user. Verify the email of this account or delete the account in Firebase, then run the command again.',
    'disabled_account' => 'A Firebase account with the email :email already exists, but it is disabled. The command did not create a user. Enable the account in Firebase or delete it, then run the command again.',
    'linked_account' => 'A Firebase account with the email :email already exists, but another user of this panel is linked to it. The command did not create a user.',
    'cleanup_failed' => 'The command could not delete the Firebase account :uid that it created. Delete this account in Firebase.',
];
