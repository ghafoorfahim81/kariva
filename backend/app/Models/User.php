<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_platform_admin' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function personalActor(): HasOne
    {
        return $this->hasOne(Actor::class, 'personal_user_id');
    }

    /** Every actor this user may act as, with the role in the pivot. */
    public function actors(): BelongsToMany
    {
        return $this->belongsToMany(Actor::class, 'actor_members')->withPivot(['role', 'is_primary']);
    }
}
