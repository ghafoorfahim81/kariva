<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PortfolioItem extends Model
{
    use HasUuids;

    protected $table = 'portfolio_items';

    protected $guarded = [];
}
