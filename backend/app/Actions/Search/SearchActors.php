<?php

namespace App\Actions\Search;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds people and pages that have a profession or an active service. Words are matched against the normalized `search_text` (trigram index).
 * Order: verified first, then average rating, then completed jobs, then id as the tie-breaker.
 * Pages are cut with a keyset cursor over exactly that order, never with an offset.
 */
class SearchActors
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /** @return array{items: Collection<int, Actor>, next_cursor: ?string} */
    public function __invoke(?string $q, ?string $category, ?string $city, ?string $cursor, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = Actor::query()
            ->leftJoin('actor_stats as s', 's.actor_id', '=', 'actors.id')
            ->where('actors.status', 'active')
            ->where(fn ($q) => $q->whereNotNull('actors.category_id')   // a profile still without a profession is not listed yet
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('services')->whereColumn('services.actor_id', 'actors.id')->where('services.status', 'active')))
            ->select('actors.*')
            ->selectRaw('(actors.verified_at IS NOT NULL)::int AS rank_verified')
            ->selectRaw('COALESCE(s.rating_avg, 0) AS rank_rating')
            ->selectRaw('COALESCE(s.completed_count, 0) AS rank_completed')
            ->orderByRaw('rank_verified DESC')->orderByRaw('rank_rating DESC')->orderByRaw('rank_completed DESC')->orderBy('actors.id')
            ->with(['category', 'city', 'stats', 'media']);

        $this->words($query, $q);
        $this->category($query, $category);
        $this->city($query, $city);
        $this->after($query, $cursor);

        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();

        return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? $this->encode($items->last()) : null];
    }

    private function words(Builder $query, ?string $q): void
    {
        foreach (array_slice(array_filter(explode(' ', Text::search((string) $q))), 0, 6) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            // substring match uses the trigram index; the second branch forgives small typos
            $query->whereRaw('(actors.search_text ILIKE ? OR ? <% actors.search_text)', [$like, $word]);
        }
    }

    /** A category (id or slug) also matches its sub-categories, on the actor itself or on any of its active services. */
    private function category(Builder $query, ?string $category): void
    {
        if ($category === null || $category === '') {
            return;
        }

        $root = DB::table('categories')->where(Str::isUuid($category) ? 'id' : 'slug', $category)->first(['id']);
        $ids = $root ? DB::table('categories')->where('id', $root->id)->orWhere('parent_id', $root->id)->pluck('id')->all() : [];

        $query->where(fn ($q) => $q
            ->whereIn('actors.category_id', $ids)
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('services')
                ->whereColumn('services.actor_id', 'actors.id')->where('services.status', 'active')->whereIn('services.category_id', $ids)));
    }

    /** Lives in the city or says it serves it. */
    private function city(Builder $query, ?string $city): void
    {
        if ($city === null || $city === '') {
            return;
        }

        $query->where(fn ($q) => $q
            ->where('actors.city_id', $city)
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('actor_service_areas')
                ->whereColumn('actor_service_areas.actor_id', 'actors.id')->where('actor_service_areas.city_id', $city)));
    }

    private function after(Builder $query, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        $parts = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($parts) || count($parts) !== 4 || ! Str::isUuid((string) $parts[3])) {
            throw new ApiException('search.bad_cursor', 422);
        }
        [$v, $r, $c, $id] = $parts;

        $query->whereRaw(
            '(((actors.verified_at IS NOT NULL)::int < ?)
              OR ((actors.verified_at IS NOT NULL)::int = ? AND COALESCE(s.rating_avg, 0) < ?)
              OR ((actors.verified_at IS NOT NULL)::int = ? AND COALESCE(s.rating_avg, 0) = ? AND COALESCE(s.completed_count, 0) < ?)
              OR ((actors.verified_at IS NOT NULL)::int = ? AND COALESCE(s.rating_avg, 0) = ? AND COALESCE(s.completed_count, 0) = ? AND actors.id > ?))',
            [(int) $v, (int) $v, (string) $r, (int) $v, (string) $r, (int) $c, (int) $v, (string) $r, (int) $c, $id]
        );
    }

    private function encode(Actor $last): string
    {
        $json = json_encode([(int) $last->rank_verified, (string) $last->rank_rating, (int) $last->rank_completed, $last->id]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
