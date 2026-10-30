<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of an order (the database refuses updates and deletes). */
class OrderEvent extends Model
{
    use HasUuids;

    protected $table = 'order_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['at' => 'datetime', 'meta' => 'array'];
}
