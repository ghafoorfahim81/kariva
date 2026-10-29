<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Served under /api/v1. Feature routes are added from Phase 1 on.
Route::get('/ping', fn () => response()->json([
    'app' => 'kariva',
    'database' => DB::selectOne('select 1 as ok')->ok === 1,
]));
