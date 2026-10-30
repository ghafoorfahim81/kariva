<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Run before every production deploy: `php artisan kariva:preflight`. A failure means "do not go live". */
class Preflight extends Command
{
    protected $signature = 'kariva:preflight {--production : Check as if APP_ENV were production}';

    protected $description = 'Check that the settings are safe for a live server';

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();
        $problems = [];
        $check = function (bool $ok, string $problem) use (&$problems) {
            if (! $ok) {
                $problems[] = $problem;
            }
        };

        $check(! empty(config('app.key')), 'APP_KEY is empty.');
        $check(! config('app.debug'), 'APP_DEBUG must be false (it shows internals to visitors).');
        $check($production, 'APP_ENV is not production.');
        $check(config('kariva.otp.driver') === 'http', 'OTP_DRIVER must be "http": with "log" nobody receives a login code.');
        $check(empty(config('kariva.otp.fake_code')), 'OTP_FAKE_CODE must be empty: it lets anybody log in as anybody.');
        $check(config('kariva.media.scanner') === 'clamav', 'MEDIA_SCANNER must be "clamav": otherwise uploads are not scanned.');
        $check(config('queue.default') !== 'sync', 'QUEUE_CONNECTION must not be "sync".');
        $check(config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true (HTTPS only).');
        $check(config('database.default') === 'pgsql', 'The database must be PostgreSQL.');
        $check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must start with https://.');
        $check(! in_array(config('kariva.media.private_disk'), [config('kariva.media.public_disk')], true), 'Private and public files must use different disks.');
        $check((int) config('kariva.otp.max_sends_per_hour') <= 5, 'The OTP limit per phone is too high.');

        try {
            DB::selectOne('select 1');
            $check((bool) DB::selectOne("select 1 as ok from pg_extension where extname = 'pg_trgm'"), 'The pg_trgm extension is missing (search needs it).');
        } catch (\Throwable) {
            $check(false, 'The database cannot be reached.');
        }

        if ($problems === []) {
            $this->info('Preflight passed.');

            return self::SUCCESS;
        }
        foreach ($problems as $p) {
            $this->error('✗ '.$p);
        }

        return self::FAILURE;
    }
}
