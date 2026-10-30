<?php

namespace App\Actions\Search;

use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the normalized `search_text` that the pg_trgm index searches.
 * Category names (all languages) and their aliases, the city and the service titles are all in it,
 * so "پلمبر", "نل‌ساز" and "plumber" find the same people.
 */
class RefreshSearchText
{
    public function actor(string $actorId): void
    {
        $actor = DB::table('actors')->where('id', $actorId)->first(['display_name', 'category_id', 'city_id']);
        if (! $actor) {
            return;
        }

        $titles = DB::table('services')->where('actor_id', $actorId)->where('status', 'active')->pluck('title')->all();
        $parts = [$actor->display_name, ...$titles, ...$this->categoryTerms($actor->category_id), ...$this->cityTerms($actor->city_id)];

        DB::table('actors')->where('id', $actorId)->update(['search_text' => $this->join($parts)]);
    }

    public function service(string $serviceId): void
    {
        $service = DB::table('services')->join('actors', 'actors.id', '=', 'services.actor_id')
            ->where('services.id', $serviceId)->first(['services.title', 'services.category_id', 'actors.city_id']);
        if (! $service) {
            return;
        }

        $parts = [$service->title, ...$this->categoryTerms($service->category_id), ...$this->cityTerms($service->city_id)];

        DB::table('services')->where('id', $serviceId)->update(['search_text' => $this->join($parts)]);
    }

    /** Names and aliases of the category and its parent. */
    private function categoryTerms(?string $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        $terms = [];
        $row = DB::table('categories')->where('id', $categoryId)->first();
        while ($row) {
            array_push($terms, $row->name, $row->name_ps, $row->name_en, ...$this->pgArray($row->aliases));
            $row = $row->parent_id ? DB::table('categories')->where('id', $row->parent_id)->first() : null;
        }

        return $terms;
    }

    private function cityTerms(?string $cityId): array
    {
        if ($cityId === null) {
            return [];
        }
        $city = DB::table('cities')->where('id', $cityId)->first(['name', 'name_ps', 'name_en']);

        return $city ? [$city->name, $city->name_ps, $city->name_en] : [];
    }

    private function join(array $parts): string
    {
        $normalized = array_map(fn ($p) => Text::search((string) $p), array_filter($parts));

        return implode(' ', array_unique(array_filter($normalized)));
    }

    /** Parses a PostgreSQL text[] literal such as {a,"b c"}. */
    private function pgArray(?string $literal): array
    {
        if ($literal === null || $literal === '{}') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/u', $literal, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => stripcslashes($x[1] !== '' ? $x[1] : ($x[2] ?? '')), $m);
    }
}
