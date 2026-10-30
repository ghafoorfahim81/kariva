<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Daily backup of the database (pg_dump, custom format) and, when files live on the local disk, of the uploads.
 * Old backups are removed after `kariva.backup.keep_days`. When a `backup` disk is configured (for example S3
 * in another region) every file is copied there too, so one dead server never means lost data.
 */
class Backup extends Command
{
    protected $signature = 'kariva:backup {--skip-files : Only the database}';

    protected $description = 'Back up the database and uploaded files, and remove old backups';

    public function handle(): int
    {
        $dir = config('kariva.backup.path');
        @mkdir($dir, 0700, true);
        $stamp = now()->format('Ymd-His');

        $db = config('database.connections.pgsql');
        $dump = "$dir/db-$stamp.dump";
        $result = Process::timeout(1800)->env(['PGPASSWORD' => (string) $db['password']])->run([
            'pg_dump', '--format=custom', '--no-owner', '--host', $db['host'], '--port', (string) $db['port'], '--username', $db['username'], '--file', $dump, $db['database'],
        ]);
        if (! $result->successful() || ! is_file($dump) || filesize($dump) === 0) {
            $this->error('Database backup failed: '.$result->errorOutput());

            return self::FAILURE;
        }
        $made = [$dump];

        if (! $this->option('skip-files') && is_dir($files = config('kariva.backup.files_path'))) {
            $archive = "$dir/files-$stamp.tar.gz";
            $tar = Process::timeout(1800)->run(['tar', '-czf', $archive, '-C', dirname($files), basename($files)]);
            if (! $tar->successful()) {
                $this->error('Files backup failed: '.$tar->errorOutput());

                return self::FAILURE;
            }
            $made[] = $archive;
        }

        if (config('kariva.backup.disk')) {
            foreach ($made as $file) {
                Storage::disk(config('kariva.backup.disk'))->put('backups/'.basename($file), fopen($file, 'r'));
            }
        }

        $this->prune($dir);
        Cache::forever('backup:last_success', time());
        $this->info('Backup done: '.implode(', ', array_map('basename', $made)));

        return self::SUCCESS;
    }

    private function prune(string $dir): void
    {
        $limit = time() - config('kariva.backup.keep_days') * 86400;
        foreach (glob("$dir/{db,files}-*", GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
