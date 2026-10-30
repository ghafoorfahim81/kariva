<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasUuids;

    protected $table = 'reviews';

    protected $guarded = [];

    protected $casts = ['tags' => 'array', 'replied_at' => 'datetime'];
}
