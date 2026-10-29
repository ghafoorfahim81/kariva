<?php

namespace App\Actions\Actors;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Two steps: any owner requests, the primary owner confirms. A page with open orders cannot be deleted. */
class DeleteBusinessActor
{
    public function request(User $by, Actor $actor): void
    {
        $actor->update(['deletion_requested_by' => $by->id, 'deletion_requested_at' => now()]);
        AuditLog::write('actor.deletion_requested', $by->id, $actor->id, 'actor', $actor->id);
    }

    public function cancel(User $by, Actor $actor): void
    {
        $actor->update(['deletion_requested_by' => null, 'deletion_requested_at' => null, 'deletion_confirmed_by' => null]);
        AuditLog::write('actor.deletion_cancelled', $by->id, $actor->id, 'actor', $actor->id);
    }

    public function confirm(User $by, Actor $actor): void
    {
        if ($actor->deletion_requested_by === null) {
            throw new ApiException('actor.deletion_not_requested', 409);
        }

        try {
            DB::transaction(fn () => $actor->update(['deletion_confirmed_by' => $by->id, 'status' => 'deleted', 'deleted_at' => now()]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'open orders')) {
                throw new ApiException('actor.has_open_orders', 409);
            }
            throw $e;
        }
        AuditLog::write('actor.deleted', $by->id, $actor->id, 'actor', $actor->id);
    }
}
