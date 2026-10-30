<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ReviewVerification;
use App\Http\Controllers\Controller;
use App\Models\VerificationRequest;
use App\Support\AuditLog;
use App\Support\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VerificationReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $limit = (int) ($data['limit'] ?? 20);

        $query = VerificationRequest::with('actor')->where('status', $data['status'] ?? 'pending')
            ->orderBy('created_at')->orderBy('id');   // oldest first: the queue is worked in order
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'admin.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '>', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '>', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($r) => $this->data($r)),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode($last->created_at, $last->id) : null,
        ]);
    }

    public function show(VerificationRequest $verification): JsonResponse
    {
        return response()->json($this->data($verification->load('actor')));
    }

    /** Streams a private document to the admin. Every view is written to the audit log. */
    public function document(Request $request, string $media)
    {
        $m = DB::table('media')->where(['id' => $media, 'model_type' => 'verification_request'])->first();
        abort_unless($m, 404);
        $vr = VerificationRequest::findOrFail($m->model_id);
        AuditLog::write('admin.document_viewed', $request->user()->id, $vr->actor_id, 'verification_request', $vr->id, ['media_id' => $m->id]);

        return Storage::disk($m->disk)->response($m->path, null, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function approve(Request $request, VerificationRequest $verification, ReviewVerification $review): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json($this->data($review($this->admin(), $verification, true, $note)->load('actor')));
    }

    public function reject(Request $request, VerificationRequest $verification, ReviewVerification $review): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json($this->data($review($this->admin(), $verification, false, $note)->load('actor')));
    }

    private function admin()
    {
        return request()->user();
    }

    private function data(VerificationRequest $r): array
    {
        $phone = $r->actor->type === 'person'
            ? DB::table('users')->where('id', $r->actor->personal_user_id)->value('phone_e164')
            : DB::table('users')->where('id', $r->requested_by)->value('phone_e164');

        return [
            'id' => $r->id,
            'status' => $r->status,
            'kind' => $r->kind,
            'actor' => ['id' => $r->actor->id, 'display_name' => $r->actor->display_name, 'type' => $r->actor->type],
            'requester_phone' => $phone,
            'documents' => DB::table('media')->where(['model_type' => 'verification_request', 'model_id' => $r->id])->orderBy('created_at')->get(['id', 'mime', 'size_bytes', 'scan_status'])
                ->map(fn ($m) => ['id' => $m->id, 'mime' => $m->mime, 'size' => (int) $m->size_bytes, 'scan_status' => $m->scan_status]),
            'note' => $r->admin_note,
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
