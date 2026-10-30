<?php

namespace App\Http\Controllers;

use App\Actions\Members\InviteMember;
use App\Actions\Members\ManageMembers;
use App\Actions\Members\RespondToInvitation;
use App\Http\Requests\PhoneRequest;
use App\Models\Actor;
use App\Models\ActorInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    /** Team of an actor. Phone numbers are shown only to people who manage members. */
    public function index(Request $request, Actor $actor): JsonResponse
    {
        $this->authorize('view', $actor);
        $canSeePhones = $request->user()->can('manageMembers', $actor);

        $members = DB::table('actor_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('actors as p', 'p.personal_user_id', '=', 'u.id')
            ->where('m.actor_id', $actor->id)
            ->orderByDesc('m.is_primary')->orderBy('m.created_at')
            ->get(['m.user_id', 'm.role', 'm.is_primary', 'p.display_name', 'u.phone_e164'])
            ->map(fn ($m) => [
                'user_id' => $m->user_id,
                'display_name' => $m->display_name,
                'role' => $m->role,
                'is_primary' => (bool) $m->is_primary,
                'phone' => $canSeePhones ? $m->phone_e164 : null,
            ]);

        return response()->json(['data' => $members]);
    }

    public function update(Request $request, Actor $actor, string $user, ManageMembers $members): JsonResponse
    {
        $this->authorize('manageMembers', $actor);
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])]]);

        $members->changeRole($request->user(), $actor, $user, $data['role']);

        return response()->json(null, 204);
    }

    /** Managers remove members; any member may remove themselves. */
    public function destroy(Request $request, Actor $actor, string $user, ManageMembers $members): JsonResponse
    {
        if ($request->user()->id === $user) {
            $this->authorize('view', $actor);
        } else {
            $this->authorize('manageMembers', $actor);
        }

        $members->remove($request->user(), $actor, $user);

        return response()->json(null, 204);
    }

    // ---------------------------------------------------------------- invitations

    public function invitations(Actor $actor): JsonResponse
    {
        $this->authorize('manageMembers', $actor);

        $rows = ActorInvitation::where('actor_id', $actor->id)->where('status', 'pending')->where('expires_at', '>', now())->latest()->get();

        return response()->json(['data' => $rows->map(fn ($i) => $this->invitationData($i, withPhone: true))]);
    }

    public function invite(PhoneRequest $request, Actor $actor, InviteMember $invite): JsonResponse
    {
        $kind = $request->input('kind', 'member');
        abort_unless(in_array($kind, ['member', 'transfer_primary'], true), 422);
        $this->authorize($kind === 'transfer_primary' ? 'transferPrimary' : 'manageMembers', $actor);

        $role = $request->validate(['role' => [$kind === 'member' ? 'required' : 'nullable', Rule::in(['owner', 'admin', 'editor', 'viewer'])]])['role'] ?? 'owner';
        $invitation = $invite($request->user(), $actor, $request->validated('phone'), $role, $kind);

        return response()->json($this->invitationData($invitation, withPhone: true), 201);
    }

    public function cancelInvitation(Actor $actor, ActorInvitation $invitation): JsonResponse
    {
        abort_unless($invitation->actor_id === $actor->id, 404);
        $this->authorize($invitation->kind === 'transfer_primary' ? 'transferPrimary' : 'manageMembers', $actor);
        abort_unless($invitation->status === 'pending', 409);

        $invitation->update(['status' => 'cancelled']);

        return response()->json(null, 204);
    }

    /** Invitations waiting for the signed-in user (matched by phone number). */
    public function mine(Request $request): JsonResponse
    {
        $rows = ActorInvitation::with(['actor', 'invitedBy'])
            ->where('invited_phone_e164', $request->user()->phone_e164)
            ->where('status', 'pending')->where('expires_at', '>', now())->latest()->get();

        return response()->json(['data' => $rows->map(fn ($i) => $this->invitationData($i) + [
            'actor' => ['id' => $i->actor->id, 'display_name' => $i->actor->display_name, 'type' => $i->actor->type],
        ])]);
    }

    public function respond(Request $request, ActorInvitation $invitation, RespondToInvitation $respond): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        $result = $respond($request->user(), $invitation, (bool) $data['accept']);

        return response()->json($this->invitationData($result));
    }

    private function invitationData(ActorInvitation $i, bool $withPhone = false): array
    {
        return [
            'id' => $i->id,
            'actor_id' => $i->actor_id,
            'kind' => $i->kind,
            'role' => $i->role,
            'status' => $i->status,
            'expires_at' => $i->expires_at->toIso8601String(),
        ] + ($withPhone ? ['phone' => $i->invited_phone_e164] : []);
    }
}
