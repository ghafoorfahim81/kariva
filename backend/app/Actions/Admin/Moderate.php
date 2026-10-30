<?php

namespace App\Actions\Admin;

use App\Actions\Stats\RefreshActorStats;
use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/** Actions an admin can take on a reported (or any) subject. Every one is written to audit_logs. */
class Moderate
{
    public function __construct(private RefreshActorStats $stats) {}

    /** Suspension only stops new orders and hides the profile; existing work goes on. */
    public function suspendActor(User $admin, Actor $actor, bool $suspend, ?string $reason): void
    {
        if ($actor->status === 'deleted') {
            throw new ApiException('admin.actor_deleted', 409);
        }
        $actor->update(['status' => $suspend ? 'suspended' : 'active']);
        AuditLog::write($suspend ? 'admin.actor_suspended' : 'admin.actor_unsuspended', $admin->id, $actor->id, 'actor', $actor->id, ['reason' => $reason]);
        Notifier::actor($actor->id, $suspend ? 'actor.suspended' : 'actor.unsuspended', "actor:{$actor->id}:".($suspend ? 'suspended' : 'unsuspended').':'.now()->timestamp, ['reason' => $reason]);
    }

    /** A suspended user cannot log in and is signed out on the next request. */
    public function suspendUser(User $admin, User $user, bool $suspend, ?string $reason): void
    {
        if ($user->id === $admin->id) {
            throw new ApiException('admin.cannot_suspend_self', 422);
        }
        $user->update(['status' => $suspend ? 'suspended' : 'active']);
        if ($suspend) {
            $user->tokens()->delete();
        }
        AuditLog::write($suspend ? 'admin.user_suspended' : 'admin.user_unsuspended', $admin->id, null, 'user', $user->id, ['reason' => $reason]);
    }

    /** An abusive or fake review is removed; the provider's rating is recomputed. */
    public function deleteReview(User $admin, Review $review, ?string $reason): void
    {
        DB::transaction(function () use ($admin, $review, $reason) {
            $providerId = $review->provider_actor_id;
            AuditLog::write('admin.review_deleted', $admin->id, $providerId, 'review', $review->id, ['stars' => $review->stars, 'reason' => $reason]);
            $review->delete();
            ($this->stats)($providerId);
        });
    }

    public function archiveService(User $admin, Service $service, ?string $reason): void
    {
        $service->update(['status' => 'archived']);
        AuditLog::write('admin.service_archived', $admin->id, $service->actor_id, 'service', $service->id, ['reason' => $reason]);
    }
}
