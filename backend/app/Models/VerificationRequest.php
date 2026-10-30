<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationRequest extends Model
{
    use HasUuids;

    protected $table = 'verification_requests';

    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime', 'files_purge_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class);
    }
}
