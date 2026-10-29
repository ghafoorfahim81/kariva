<?php

namespace App\Actions\Members;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ManageMembers
{
    /** Change a member's role. Admins can never touch owners or hand out the owner role. */
    public function changeRole(User $by, Actor $actor, string $userId, string $role): void
    {
        $target = $this->member($actor, $userId);
        $mine = $actor->membershipOf($by);

        if ($target->is_primary) {
            throw new ApiException('member.primary_locked', 422);
        }
        if ($mine->role === 'admin' && ($target->role === 'owner' || $role === 'owner')) {
            throw new ApiException('member.admin_cannot_manage_owners', 403);
        }

        $this->guardLastOwner(fn () => DB::table('actor_members')->where(['actor_id' => $actor->id, 'user_id' => $userId])
            ->update(['role' => $role, 'updated_at' => now()]));
        AuditLog::write('member.role_changed', $by->id, $actor->id, 'user', $userId, ['from' => $target->role, 'to' => $role]);
    }

    /** Remove a member, or let a member leave (a user may always remove themselves, except the primary owner). */
    public function remove(User $by, Actor $actor, string $userId): void
    {
        $target = $this->member($actor, $userId);
        $mine = $actor->membershipOf($by);
        $isSelf = $by->id === $userId;

        if ($target->is_primary) {
            throw new ApiException('member.primary_locked', 422);
        }
        if (! $isSelf && $mine->role === 'admin' && $target->role === 'owner') {
            throw new ApiException('member.admin_cannot_manage_owners', 403);
        }

        $this->guardLastOwner(fn () => DB::table('actor_members')->where(['actor_id' => $actor->id, 'user_id' => $userId])->delete());
        AuditLog::write($isSelf ? 'member.left' : 'member.removed', $by->id, $actor->id, 'user', $userId, ['role' => $target->role]);
    }

    private function member(Actor $actor, string $userId): object
    {
        return DB::table('actor_members')->where(['actor_id' => $actor->id, 'user_id' => $userId])->first()
            ?? throw new ApiException('member.not_found', 404);
    }

    private function guardLastOwner(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'last owner')) {
                throw new ApiException('member.last_owner', 422);
            }
            throw $e;
        }
    }
}
