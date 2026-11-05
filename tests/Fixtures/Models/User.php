<?php

namespace Isapp\FilamentFirebaseAuth\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Isapp\FilamentFirebaseAuth\Concerns\HasFirebaseIdentity;

class User extends Authenticatable implements CanResetPassword, FilamentUser, MustVerifyEmail
{
    use HasFirebaseIdentity;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
