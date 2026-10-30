<?php

use App\Jobs\PurgeMedia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PurgeMedia)->dailyAt('03:00')->timezone(config('kariva.display_timezone'));

// Time-based rules of the order flow. Hourly is precise enough for waits of days.
Schedule::command('orders:expire')->hourly()->withoutOverlapping();
Schedule::command('orders:auto-complete')->hourly()->withoutOverlapping();
Schedule::command('needs:expire')->hourly()->withoutOverlapping();

// Operations: a heartbeat for the health check, and the daily backup (02:30 Kabul time, when nobody is online).
Schedule::command('kariva:heartbeat')->everyMinute();
Schedule::command('kariva:backup')->dailyAt('02:30')->timezone(config('kariva.display_timezone'))->withoutOverlapping();
