<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * `GET /api/v1/health` is for the uptime monitor: 200 when the app can serve, 503 when it cannot.
 * `GET /api/v1/admin/health` (admins) adds the details: queue backlog, scheduler heartbeat, disk, last backup.
 */
class HealthController extends Controller
{
    public function basic(): JsonResponse
    {
        $ok = $this->database()['ok'] && $this->cache()['ok'];

        return response()->json(['status' => $ok ? 'ok' : 'down'], $ok ? 200 : 503);
    }

    public function detailed(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'disk' => $this->disk(),
            'backup' => $this->backup(),
        ];

        return response()->json(['status' => collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'attention', 'checks' => $checks]);
    }

    private function database(): array
    {
        try {
            DB::selectOne('select 1');

            return ['ok' => true];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    private function cache(): array
    {
        try {
            Cache::put('health:ping', 1, 10);

            return ['ok' => Cache::get('health:ping') === 1];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    /** Jobs waiting and how old the oldest one is: a growing age means the worker is not running. */
    private function queue(): array
    {
        try {
            if (config('queue.default') === 'redis') {
                $waiting = (int) Redis::connection(config('queue.connections.redis.connection', 'default'))->llen('queues:'.config('queue.connections.redis.queue', 'default'));

                return ['ok' => $waiting < 500, 'waiting' => $waiting, 'failed' => (int) DB::table('failed_jobs')->count()];
            }
            $oldest = DB::table('jobs')->min('available_at');

            return ['ok' => $oldest === null || time() - (int) $oldest < 600, 'waiting' => (int) DB::table('jobs')->count(), 'failed' => (int) DB::table('failed_jobs')->count()];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    /** `kariva:heartbeat` runs every minute from the scheduler; if it stops, the cron entry is broken. */
    private function scheduler(): array
    {
        $last = Cache::get('scheduler:heartbeat');

        return ['ok' => $last !== null && time() - (int) $last < 300, 'last_run' => $last ? date(DATE_ATOM, (int) $last) : null];
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $pct = $free && $total ? round($free / $total * 100, 1) : null;

        return ['ok' => $pct === null || $pct > 10, 'free_percent' => $pct];
    }

    private function backup(): array
    {
        $last = Cache::get('backup:last_success');

        return ['ok' => $last !== null && time() - (int) $last < 36 * 3600, 'last_success' => $last ? date(DATE_ATOM, (int) $last) : null];
    }
}
