<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Moderate;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Support\Cursor;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ModerationController extends Controller
{
    /** Numbers for the dashboard. */
    public function overview(): JsonResponse
    {
        $count = fn (string $table, ?callable $where = null) => (int) DB::table($table)->when($where, fn ($q) => $where($q))->count();

        return response()->json([
            'users' => $count('users', fn ($q) => $q->whereNull('deleted_at')),
            'actors' => ['person' => $count('actors', fn ($q) => $q->where('type', 'person')->where('status', '!=', 'deleted')), 'business' => $count('actors', fn ($q) => $q->where('type', 'business')->where('status', '!=', 'deleted'))],
            'verified_actors' => $count('actors', fn ($q) => $q->whereNotNull('verified_at')),
            'pending_verifications' => $count('verification_requests', fn ($q) => $q->where('status', 'pending')),
            'open_reports' => $count('reports', fn ($q) => $q->where('status', 'open')),
            'suspended_actors' => $count('actors', fn ($q) => $q->where('status', 'suspended')),
            'orders_last_7_days' => $count('orders', fn ($q) => $q->where('created_at', '>=', now()->subDays(7))),
            'completed_orders' => $count('orders', fn ($q) => $q->where('status', 'completed')),
            'open_needs' => $count('needs', fn ($q) => $q->where('status', 'open')),
        ]);
    }

    /** Search people and pages by name or phone. Admins see phone numbers; nobody else does. */
    public function actors(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])],
            'type' => ['nullable', Rule::in(['person', 'business'])],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);
        $q = trim((string) ($data['q'] ?? ''));
        $phone = $q !== '' ? Phone::normalize($q) : null;

        $query = DB::table('actors as a')->leftJoin('users as u', 'u.id', '=', 'a.personal_user_id')
            ->when($data['status'] ?? null, fn ($x, $s) => $x->where('a.status', $s))
            ->when($data['type'] ?? null, fn ($x, $t) => $x->where('a.type', $t))
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('a.display_name', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->when($phone, fn ($z) => $z->orWhere('u.phone_e164', $phone)->orWhere('a.contact_phone_e164', $phone))))
            ->orderByDesc('a.created_at')->orderByDesc('a.id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'admin.bad_cursor');
            $query->where(fn ($x) => $x->where('a.created_at', '<', $at)->orWhere(fn ($y) => $y->where('a.created_at', $at)->where('a.id', '<', $id)));
        }
        $rows = $query->limit($limit + 1)->get(['a.id', 'a.type', 'a.display_name', 'a.status', 'a.verified_at', 'a.created_at', 'a.personal_user_id', 'u.phone_e164 as user_phone', 'u.status as user_status', 'a.contact_phone_e164']);
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'display_name' => $a->display_name, 'status' => $a->status, 'verified' => $a->verified_at !== null,
                'phone' => $a->user_phone ?? $a->contact_phone_e164, 'user_id' => $a->personal_user_id, 'user_status' => $a->user_status,
                'created_at' => date(DATE_ATOM, strtotime($a->created_at)),
            ]),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode(new \DateTimeImmutable($last->created_at), $last->id) : null,
        ]);
    }

    public function suspendActor(Request $request, Actor $actor, Moderate $m): JsonResponse
    {
        $m->suspendActor($request->user(), $actor, true, $this->reason($request));

        return response()->json(['status' => $actor->refresh()->status]);
    }

    public function unsuspendActor(Request $request, Actor $actor, Moderate $m): JsonResponse
    {
        $m->suspendActor($request->user(), $actor, false, $this->reason($request));

        return response()->json(['status' => $actor->refresh()->status]);
    }

    public function suspendUser(Request $request, User $user, Moderate $m): JsonResponse
    {
        $m->suspendUser($request->user(), $user, true, $this->reason($request));

        return response()->json(['status' => $user->refresh()->status]);
    }

    public function unsuspendUser(Request $request, User $user, Moderate $m): JsonResponse
    {
        $m->suspendUser($request->user(), $user, false, $this->reason($request));

        return response()->json(['status' => $user->refresh()->status]);
    }

    public function deleteReview(Request $request, Review $review, Moderate $m): JsonResponse
    {
        $m->deleteReview($request->user(), $review, $this->reason($request));

        return response()->json(null, 204);
    }

    public function archiveService(Request $request, Service $service, Moderate $m): JsonResponse
    {
        $m->archiveService($request->user(), $service, $this->reason($request));

        return response()->json(['status' => 'archived']);
    }

    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['nullable', 'string', 'max:80'], 'actor_id' => ['nullable', 'uuid'], 'cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['limit'] ?? 30);

        $query = DB::table('audit_logs')
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($data['actor_id'] ?? null, fn ($q, $a) => $q->where('actor_id', $a))
            ->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'admin.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'user_id' => $l->user_id, 'actor_id' => $l->actor_id, 'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id, 'meta' => json_decode($l->meta, true), 'created_at' => date(DATE_ATOM, strtotime($l->created_at))]),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode(new \DateTimeImmutable($last->created_at), $last->id) : null,
        ]);
    }

    private function reason(Request $request): ?string
    {
        return $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
    }
}
