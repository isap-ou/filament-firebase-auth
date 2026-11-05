<?php

namespace Isapp\FilamentFirebaseAuth\Enums;

/**
 * The values of the `delete_accounts` config key.
 */
enum DeleteAccounts: string
{
    case Never = 'never';
    case NewOnly = 'new_only';
    case Orphaned = 'orphaned';
}
