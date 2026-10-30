<?php

namespace App\Actions\Admin;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Support\AuditLog;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * An admin looks at the document photos and decides. Approving sets the verified badge.
 * Either way the photos get a purge date; the nightly job deletes them. The document number is never stored.
 */
class ReviewVerification
{
    public function __invoke(User $admin, VerificationRequest $request, bool $approve, ?string $note): VerificationRequest
    {
        if (! $approve && ($note === null || trim($note) === '')) {
            throw new ApiException('verification.note_required', 422);   // a rejection must explain why
        }

        return DB::transaction(function () use ($admin, $request, $approve, $note) {
            $r = VerificationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                throw new ApiException('verification.not_pending', 409);
            }

            $purgeAt = now()->addDays(config('kariva.verification_files_days'));
            $r->update([
                'status' => $approve ? 'approved' : 'rejected',
                'admin_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'files_purge_at' => $purgeAt,
            ]);
            DB::table('media')->where(['model_type' => 'verification_request', 'model_id' => $r->id])->update(['purge_at' => $purgeAt]);
            if ($approve) {
                DB::table('actors')->where('id', $r->actor_id)->update(['verified_at' => now(), 'updated_at' => now()]);
            }

            AuditLog::write($approve ? 'admin.verification_approved' : 'admin.verification_rejected', $admin->id, $r->actor_id, 'verification_request', $r->id, ['kind' => $r->kind]);
            Notifier::actor($r->actor_id, $approve ? 'verification.approved' : 'verification.rejected', "verification:{$r->id}:decision", ['note' => $r->admin_note]);

            return $r->refresh();
        });
    }
}
