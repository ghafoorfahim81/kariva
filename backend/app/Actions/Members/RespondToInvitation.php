<?php

namespace App\Actions\Members;

use App\Exceptions\ApiException;
use App\Models\ActorInvitation;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class RespondToInvitation
{
    public function __invoke(User $user, ActorInvitation $invitation, bool $accept): ActorInvitation
    {
        if ($invitation->invited_phone_e164 !== $user->phone_e164) {
            throw new ApiException('invitation.not_found', 404);   // someone else's invitation looks missing
        }
        if ($invitation->status === 'pending' && $invitation->isExpired()) {
            $invitation->update(['status' => 'expired']);   // outside the transaction below, so the change is kept
            throw new ApiException('invitation.expired', 410);
        }

        return DB::transaction(function () use ($user, $invitation, $accept) {
            $invitation = ActorInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($invitation->status !== 'pending') {
                throw new ApiException('invitation.not_pending', 409);
            }

            if ($accept) {
                $invitation->kind === 'transfer_primary' ? $this->transferPrimary($user, $invitation) : $this->join($user, $invitation);
            }

            $invitation->update([
                'status' => $accept ? 'accepted' : 'declined',
                'responded_at' => now(),
                'invited_user_id' => $user->id,
            ]);
            AuditLog::write($accept ? 'invitation.accepted' : 'invitation.declined', $user->id, $invitation->actor_id, 'invitation', $invitation->id, ['kind' => $invitation->kind]);

            return $invitation;
        });
    }

    private function join(User $user, ActorInvitation $invitation): void
    {
        if (DB::table('actor_members')->where(['actor_id' => $invitation->actor_id, 'user_id' => $user->id])->exists()) {
            throw new ApiException('invitation.already_member', 409);
        }
        DB::table('actor_members')->insert(['actor_id' => $invitation->actor_id, 'user_id' => $user->id, 'role' => $invitation->role]);
    }

    /** The old primary stays on as an ordinary owner; the invitee becomes the one primary owner. */
    private function transferPrimary(User $user, ActorInvitation $invitation): void
    {
        $members = DB::table('actor_members')->where('actor_id', $invitation->actor_id);
        $current = (clone $members)->where('is_primary', true)->first();

        if (! $current || $current->user_id !== $invitation->invited_by_user_id) {
            throw new ApiException('invitation.inviter_not_primary', 409);   // ownership moved since the invitation was sent
        }

        DB::table('actor_members')->where(['actor_id' => $invitation->actor_id, 'user_id' => $current->user_id])->update(['is_primary' => false]);

        if ((clone $members)->where('user_id', $user->id)->exists()) {
            DB::table('actor_members')->where(['actor_id' => $invitation->actor_id, 'user_id' => $user->id])->update(['role' => 'owner', 'is_primary' => true]);
        } else {
            DB::table('actor_members')->insert(['actor_id' => $invitation->actor_id, 'user_id' => $user->id, 'role' => 'owner', 'is_primary' => true]);
        }

        AuditLog::write('actor.primary_owner_transferred', $user->id, $invitation->actor_id, 'actor', $invitation->actor_id, ['from' => $current->user_id, 'to' => $user->id]);
    }
}
