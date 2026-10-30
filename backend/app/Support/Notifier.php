<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * In-app notifications. One row per user; the unique (user_id, dedupe_key) makes a repeated event a no-op,
 * so a retry never notifies twice.
 */
final class Notifier
{
    /** Notify every member of an actor (a person has one member, a page has its team). */
    public static function actor(string $actorId, string $type, string $dedupeKey, array $data = [], ?string $exceptUserId = null): void
    {
        $users = DB::table('actor_members')->where('actor_id', $actorId)->pluck('user_id');

        foreach ($users as $userId) {
            if ($userId === $exceptUserId) {
                continue;
            }
            self::user($userId, $type, $dedupeKey, $data);
        }
    }

    public static function user(string $userId, string $type, string $dedupeKey, array $data = []): void
    {
        DB::table('notifications')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
