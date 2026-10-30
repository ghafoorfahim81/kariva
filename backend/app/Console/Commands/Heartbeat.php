<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Heartbeat extends Command
{
    protected $signature = 'kariva:heartbeat';

    protected $description = 'Marks the scheduler as alive (read by the health check)';

    public function handle(): int
    {
        Cache::put('scheduler:heartbeat', time(), 3600);

        return self::SUCCESS;
    }
}
