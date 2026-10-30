<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = ['agreed_amount' => 'decimal:2', 'completed_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'customer_actor_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'provider_actor_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('at')->orderBy('id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function pendingQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->where('status', 'pending');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'quoted', 'accepted', 'provider_done'], true);
    }
}
