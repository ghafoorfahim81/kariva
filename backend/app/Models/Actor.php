<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Actor extends Model
{
    use HasUuids;

    protected $table = 'actors';

    protected $guarded = [];

    protected $hidden = ['search_text'];

    protected $casts = [
        'verified_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(ActorStat::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'actor_members')->withPivot(['role', 'is_primary'])->withTimestamps();
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'actor_service_areas');
    }

    /** Membership row of the user in this actor, or null. */
    public function membershipOf(User $user): ?object
    {
        return DB::table('actor_members')->where(['actor_id' => $this->id, 'user_id' => $user->id])->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
