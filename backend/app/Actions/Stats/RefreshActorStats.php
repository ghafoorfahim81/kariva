<?php

namespace App\Actions\Stats;

use Illuminate\Support\Facades\DB;

/**
 * Recomputes the numbers shown on a profile and used for ranking. It counts from the source rows every time,
 * so running it twice (or after a missed run) always gives the right answer.
 */
class RefreshActorStats
{
    public function __invoke(string $actorId): void
    {
        DB::statement(<<<'SQL'
INSERT INTO actor_stats (actor_id, completed_count, rating_count, rating_avg, updated_at)
SELECT ?::uuid,
       (SELECT count(*) FROM orders WHERE provider_actor_id = ?::uuid AND status = 'completed'),
       (SELECT count(*) FROM reviews WHERE provider_actor_id = ?::uuid),
       (SELECT round(avg(stars)::numeric, 2) FROM reviews WHERE provider_actor_id = ?::uuid),
       now()
ON CONFLICT (actor_id) DO UPDATE
   SET completed_count = EXCLUDED.completed_count,
       rating_count = EXCLUDED.rating_count,
       rating_avg = EXCLUDED.rating_avg,
       updated_at = now()
SQL, [$actorId, $actorId, $actorId, $actorId]);
    }
}
