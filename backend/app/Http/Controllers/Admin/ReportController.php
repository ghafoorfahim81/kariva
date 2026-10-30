<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Moderate;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Review;
use App\Models\Service;
use App\Support\AuditLog;
use App\Support\Cursor;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Abuse reports: look at the subject, decide, and optionally act on it in the same step. */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'dismissed'])],
            'type' => ['nullable', Rule::in(['actor', 'service', 'review', 'message'])],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);

        $query = DB::table('reports')->where('status', $data['status'] ?? 'open')
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('reportable_type', $t))
            ->orderBy('created_at')->orderBy('id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'admin.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '>', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '>', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($r) => $this->data($r)),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode(new \DateTimeImmutable($last->created_at), $last->id) : null,
        ]);
    }

    public function resolve(Request $request, string $report, Moderate $moderate): JsonResponse
    {
        return $this->close($request, $report, 'resolved', $moderate);
    }

    public function dismiss(Request $request, string $report, Moderate $moderate): JsonResponse
    {
        return $this->close($request, $report, 'dismissed', $moderate);
    }

    private function close(Request $request, string $reportId, string $status, Moderate $moderate): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            // an optional action on the reported subject, taken in the same step
            'action' => ['nullable', Rule::in(['suspend_actor', 'delete_review', 'archive_service'])],
        ]);
        $admin = $request->user();

        $report = DB::transaction(function () use ($reportId, $status, $data, $admin, $moderate) {
            $r = DB::table('reports')->where('id', $reportId)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->status !== 'open') {
                throw new ApiException('report.not_open', 409);
            }
            if (! empty($data['action'])) {
                $this->act($data['action'], $r, $admin, $moderate, $data['note'] ?? null);
            }
            DB::table('reports')->where('id', $r->id)->update(['status' => $status, 'resolved_by' => $admin->id, 'resolved_at' => now(), 'updated_at' => now()]);
            AuditLog::write("admin.report_$status", $admin->id, null, 'report', $r->id, ['type' => $r->reportable_type, 'subject' => $r->reportable_id, 'action' => $data['action'] ?? null, 'note' => $data['note'] ?? null]);
            Notifier::user($r->reporter_user_id, 'report.'.$status, "report:{$r->id}:$status", []);

            return DB::table('reports')->where('id', $r->id)->first();
        });

        return response()->json($this->data($report));
    }

    /** The action must fit the kind of subject that was reported. */
    private function act(string $action, object $r, $admin, Moderate $moderate, ?string $note): void
    {
        match (true) {
            $action === 'suspend_actor' && $r->reportable_type === 'actor' => $moderate->suspendActor($admin, Actor::findOrFail($r->reportable_id), true, $note),
            $action === 'delete_review' && $r->reportable_type === 'review' => $moderate->deleteReview($admin, Review::findOrFail($r->reportable_id), $note),
            $action === 'archive_service' && $r->reportable_type === 'service' => $moderate->archiveService($admin, Service::findOrFail($r->reportable_id), $note),
            default => throw new ApiException('admin.action_does_not_fit', 422),
        };
    }

    private function data(object $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'type' => $r->reportable_type,
            'subject_id' => $r->reportable_id,
            'subject' => $this->subject($r->reportable_type, $r->reportable_id),
            'reason' => $r->reason,
            'details' => $r->details,
            'reporter_phone' => DB::table('users')->where('id', $r->reporter_user_id)->value('phone_e164'),
            'created_at' => date(DATE_ATOM, strtotime($r->created_at)),
            'resolved_at' => $r->resolved_at ? date(DATE_ATOM, strtotime($r->resolved_at)) : null,
        ];
    }

    /** What the admin needs to see to judge the report (null when the subject is gone). */
    private function subject(string $type, string $id): ?array
    {
        return match ($type) {
            'actor' => ($a = DB::table('actors')->where('id', $id)->first()) ? ['title' => $a->display_name, 'text' => $a->bio, 'actor_id' => $a->id, 'actor_status' => $a->status] : null,
            'service' => ($s = DB::table('services')->where('id', $id)->first()) ? ['title' => $s->title, 'text' => $s->description, 'actor_id' => $s->actor_id, 'status' => $s->status] : null,
            'review' => ($v = DB::table('reviews')->where('id', $id)->first()) ? ['title' => str_repeat('★', $v->stars), 'text' => $v->body, 'actor_id' => $v->provider_actor_id] : null,
            'message' => ($m = DB::table('messages')->where('id', $id)->first()) ? ['title' => null, 'text' => $m->body, 'actor_id' => $m->sender_actor_id] : null,
            default => null,
        };
    }
}
