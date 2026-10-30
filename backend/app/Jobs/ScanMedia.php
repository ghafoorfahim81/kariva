<?php

namespace App\Jobs;

use App\Contracts\MalwareScanner;
use App\Support\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** A file is shown only after it is scanned and marked clean. An infected file is deleted at once. */
class ScanMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $mediaId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MalwareScanner $scanner): void
    {
        $media = DB::table('media')->where('id', $this->mediaId)->first();
        if (! $media || $media->scan_status !== 'pending') {
            return;
        }

        $disk = Storage::disk($media->disk);
        if (! $disk->exists($media->path)) {
            DB::table('media')->where('id', $media->id)->delete();

            return;
        }

        if ($scanner->isClean($disk->path($media->path))) {
            DB::table('media')->where('id', $media->id)->update(['scan_status' => 'clean']);

            return;
        }

        $disk->delete($media->path);
        DB::table('media')->where('id', $media->id)->delete();
        AuditLog::write('media.infected_removed', $media->created_by, null, $media->model_type, $media->model_id, ['mime' => $media->mime]);
    }
}
