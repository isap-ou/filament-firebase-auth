<?php

namespace Isapp\FilamentFirebaseAuth\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Links one local record to one Firebase user.
 *
 * @property int $id
 * @property string $firebase_uid
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FirebaseIdentity extends Model
{
    protected $fillable = [
        'firebase_uid',
        'email_verified_at',
        'synced_at',
    ];

    public function getTable(): string
    {
        return config()->string('filament-firebase-auth.table');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forFirebaseUid(Builder $query, string $uid, string $morphType): void
    {
        $query->where('firebase_uid', $uid)
            ->where('authenticatable_type', $morphType);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
