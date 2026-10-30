<?php

namespace App\Models;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasUuids;

    protected $table = 'reviews';

    protected $guarded = [];

    protected $casts = ['tags' => PgTextArray::class, 'replied_at' => 'datetime'];
}
