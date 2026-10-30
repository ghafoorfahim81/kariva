<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Need extends Model
{
    use HasUuids;

    protected $table = 'needs';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
