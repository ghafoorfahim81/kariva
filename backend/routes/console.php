<?php

use App\Jobs\PurgeMedia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PurgeMedia)->dailyAt('03:00')->timezone(config('kariva.display_timezone'));
