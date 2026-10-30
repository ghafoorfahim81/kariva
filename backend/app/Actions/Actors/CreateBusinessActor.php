<?php

namespace App\Actions\Actors;

use App\Actions\Search\RefreshSearchText;
use App\Models\Actor;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class CreateBusinessActor
{
    public function __construct(private RefreshSearchText $search) {}

    /** A shop or company page. The creator becomes its primary owner. */
    public function __invoke(User $user, array $attributes): Actor
    {
        return DB::transaction(function () use ($user, $attributes) {
            $actor = Actor::create($attributes + ['type' => 'business']);
            DB::table('actor_members')->insert(['actor_id' => $actor->id, 'user_id' => $user->id, 'role' => 'owner', 'is_primary' => true]);
            $this->search->actor($actor->id);
            AuditLog::write('actor.created', $user->id, $actor->id, 'actor', $actor->id);

            return $actor->refresh();
        });
    }
}
