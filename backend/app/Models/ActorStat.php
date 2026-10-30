<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActorStat extends Model
{
    protected $table = 'actor_stats';

    protected $primaryKey = 'actor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    const CREATED_AT = null;
}
