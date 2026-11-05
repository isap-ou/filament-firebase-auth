<?php

namespace Isapp\FilamentFirebaseAuth\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Isapp\FilamentFirebaseAuth\Enums\Resolution;

/**
 * The answer of `ResolveLocalUser`.
 *
 * The user is unsaved for `Resolution::New` and null for `Resolution::Blocked`.
 */
final readonly class ResolvedUser
{
    public function __construct(
        public Resolution $resolution,
        public ?Authenticatable $user,
    ) {}
}
