<?php

namespace App\Actions\Members;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\ActorInvitation;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;

class InviteMember
{
    public const LIFETIME_DAYS = 7;

    /** kind = member (join the team) or transfer_primary (become the primary owner). */
    public function __invoke(User $by, Actor $actor, string $phone, string $role, string $kind): ActorInvitation
    {
        $invitee = User::where('phone_e164', $phone)->first();

        if ($by->id === $invitee?->id) {
            throw new ApiException('invitation.cannot_invite_self', 422);
        }
        if ($kind === 'member' && $invitee && $actor->membershipOf($invitee)) {
            throw new ApiException('invitation.already_member', 409);
        }
        if ($kind === 'member' && $role === 'owner' && $actor->membershipOf($by)?->role !== 'owner') {
            throw new ApiException('member.admin_cannot_manage_owners', 403);
        }

        // A finished-but-unmarked invitation would block a new one through the unique pending index.
        ActorInvitation::where(['actor_id' => $actor->id, 'invited_phone_e164' => $phone, 'kind' => $kind, 'status' => 'pending'])
            ->where('expires_at', '<=', now())->update(['status' => 'expired']);

        try {
            $invitation = ActorInvitation::create([
                'actor_id' => $actor->id,
                'kind' => $kind,
                'role' => $kind === 'transfer_primary' ? 'owner' : $role,
                'invited_phone_e164' => $phone,
                'invited_user_id' => $invitee?->id,
                'invited_by_user_id' => $by->id,
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'one_pending_invitation')) {
                throw new ApiException('invitation.already_pending', 409);
            }
            throw $e;
        }

        AuditLog::write('invitation.created', $by->id, $actor->id, 'invitation', $invitation->id, ['kind' => $kind, 'role' => $invitation->role]);

        return $invitation;
    }
}
