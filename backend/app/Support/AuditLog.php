<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sensitive actions (ownership, members, deletion, verification) are written to audit_logs, which is append-only. */
final class AuditLog
{
    public static function write(string $action, ?string $userId, ?string $actorId = null, ?string $subjectType = null, ?string $subjectId = null, array $meta = []): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip_address' => request()?->ip(),
        ]);
    }
}
