<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Service extends Model
{
    use HasUuids;

    protected $table = 'services';

    protected $guarded = [];

    protected $hidden = ['search_text'];

    protected $casts = ['price_amount' => 'decimal:2'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model')->where('scan_status', 'clean')->orderBy('sort_order');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
