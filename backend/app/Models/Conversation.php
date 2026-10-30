<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasUuids;

    protected $table = 'conversations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime'];
}
