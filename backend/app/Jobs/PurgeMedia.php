<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly clean-up. The polymorphic media table cannot have a foreign key to its parents, so this job is what
 * keeps it consistent: it removes files whose parent row is gone, and files past their purge date
 * (verification documents).
 */
class PurgeMedia implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        foreach (array_keys(Relation::morphMap()) as $type) {
            $table = (new (Relation::getMorphedModel($type)))->getTable();

            $this->purge(
                DB::table('media')->where('model_type', $type)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn("$table.id", 'media.model_id'))
            );
        }

        $this->purge(DB::table('media')->whereNotNull('purge_at')->where('purge_at', '<=', now()));
    }

    private function purge($query): void
    {
        $query->orderBy('id')->select(['id', 'disk', 'path'])->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                Storage::disk($row->disk)->delete($row->path);
            }
            DB::table('media')->whereIn('id', $rows->pluck('id'))->delete();
        }, 'id');
    }
}
