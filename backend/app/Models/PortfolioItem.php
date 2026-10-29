<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PortfolioItem extends Model
{
    use HasUuids;

    protected $table = 'portfolio_items';

    protected $guarded = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model')->where('scan_status', 'clean')->orderBy('sort_order');
    }
}
