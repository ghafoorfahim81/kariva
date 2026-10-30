<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActorInvitation extends Model
{
    use HasUuids;

    protected $table = 'actor_invitations';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'responded_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
