<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    use HasUuids;

    protected $table = 'offers';

    protected $guarded = [];

    protected $hidden = ['need_kind'];

    protected $casts = ['amount' => 'decimal:2'];

    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'provider_actor_id');
    }
}
