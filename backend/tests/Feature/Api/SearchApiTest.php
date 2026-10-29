<?php

namespace Tests\Feature\Api;

use App\Actions\Search\RefreshSearchText;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

class SearchApiTest extends ApiTestCase
{
    private function ids(string $query = ''): array
    {
        return array_column($this->getJson('/api/v1/search/actors'.($query ? "?$query" : ''))->assertOk()->json('data'), 'id');
    }

    /** A page in a category and city, with its search text built the way the app builds it. */
    private function pro(string $name, ?string $categorySlug = null, ?string $cityEn = null, array $o = []): string
    {
        $page = $this->business(null, [
            'display_name' => $name,
            'category_id' => $categorySlug ? DB::table('categories')->where('slug', $categorySlug)->value('id') : null,
            'city_id' => $cityEn ? DB::table('cities')->where('name_en', $cityEn)->value('id') : null,
        ] + ($o['actor'] ?? []));
        app(RefreshSearchText::class)->actor($page['actor']);
        if (isset($o['stats'])) {
            DB::table('actor_stats')->insert(['actor_id' => $page['actor']] + $o['stats']);
        }

        return $page['actor'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_search_is_public_and_returns_actors(): void
    {
        $id = $this->pro('برق احمد', 'electrician', 'Kabul');

        $this->assertSame([$id], $this->ids());
    }

    public function test_a_profession_name_finds_the_page(): void
    {
        $electrician = $this->pro('احمد', 'electrician', 'Kabul');
        $this->pro('کریم', 'plumber', 'Kabul');

        $this->assertSame([$electrician], $this->ids('q='.urlencode('برقکار')));
    }

    public function test_other_names_of_the_profession_work_too(): void
    {
        $plumber = $this->pro('کریم', 'plumber', 'Kabul');
        $this->pro('احمد', 'electrician', 'Kabul');

        foreach (['نل ساز', 'نلساز', 'پلمبر', 'plumber', 'لوله کش'] as $q) {
            $this->assertSame([$plumber], $this->ids('q='.urlencode($q)), $q);
        }
    }

    public function test_the_name_the_city_and_a_service_title_are_searchable(): void
    {
        $herat = $this->pro('محمود', 'electrician', 'Herat');
        $this->pro('زرمینه', 'electrician', 'Kabul');

        $this->assertSame([$herat], $this->ids('q='.urlencode('محمود')));
        $this->assertSame([$herat], $this->ids('q='.urlencode('برقکار هرات')));
        $this->assertSame([$herat], $this->ids('q='.urlencode('herat')));
    }

    public function test_arabic_letters_and_persian_digits_in_the_query_are_normalized(): void
    {
        $id = $this->pro('احمد', 'carpenter', 'Kabul');
        DB::table('actors')->where('id', $id)->update(['search_text' => 'کریم مغازه 12 نجار']);

        $this->assertSame([$id], $this->ids('q='.urlencode('كريم نجار')));
        $this->assertSame([$id], $this->ids('q='.urlencode('مغازه ۱۲')));
        $this->assertSame([$id], $this->ids('q='.urlencode('مغازه ١٢')));
    }

    public function test_every_word_must_match(): void
    {
        $this->pro('احمد', 'electrician', 'Kabul');

        $this->assertSame([], $this->ids('q='.urlencode('برقکار قندهار')));
    }

    public function test_a_small_typo_still_finds_the_page(): void
    {
        $id = $this->pro('احمد', 'plumber', 'Kabul');

        $this->assertContains($id, $this->ids('q='.urlencode('plumbr')));
    }

    public function test_like_wildcards_in_the_query_are_literal(): void
    {
        $this->pro('احمد', 'electrician', 'Kabul');

        // punctuation is dropped from the query, so these are just "no words": no SQL error, no odd matching
        $this->assertCount(1, $this->ids('q='.urlencode('احمد%')));
        $this->assertSame([], $this->ids('q='.urlencode('_zzz')));
        $this->assertCount(1, $this->ids('q='.urlencode('%')));
    }

    public function test_filter_by_category_includes_its_sub_categories_and_accepts_slug_or_id(): void
    {
        $electrician = $this->pro('احمد', 'electrician', 'Kabul');
        $this->pro('کریم', 'tailor', 'Kabul');
        $root = DB::table('categories')->where('slug', 'construction')->value('id');

        $this->assertSame([$electrician], $this->ids('category=electrician'));
        $this->assertSame([$electrician], $this->ids("category=$root"));
        $this->assertSame([$electrician], $this->ids('category=construction'));
        $this->assertSame([], $this->ids('category=nonexistent'));
    }

    public function test_a_page_is_found_by_the_category_of_any_of_its_active_services(): void
    {
        $page = $this->business(null, ['display_name' => 'همه‌کاره']);
        $this->service($page['actor'], ['category_id' => DB::table('categories')->where('slug', 'cook')->value('id'), 'status' => 'active']);
        $this->service($page['actor'], ['category_id' => DB::table('categories')->where('slug', 'tailor')->value('id'), 'status' => 'archived']);

        $this->assertSame([$page['actor']], $this->ids('category=cook'));
        $this->assertSame([], $this->ids('category=tailor'));
    }

    public function test_filter_by_city_includes_served_cities(): void
    {
        $kabul = $this->pro('احمد', 'electrician', 'Kabul');
        $herat = $this->pro('کریم', 'electrician', 'Herat');
        $heratId = DB::table('cities')->where('name_en', 'Herat')->value('id');
        $kabulId = DB::table('cities')->where('name_en', 'Kabul')->value('id');
        DB::table('actor_service_areas')->insert(['actor_id' => $kabul, 'city_id' => $heratId]);

        $this->assertEqualsCanonicalizing([$kabul, $herat], $this->ids("city=$heratId"));
        $this->assertSame([$kabul], $this->ids("city=$kabulId"));
    }

    public function test_only_active_pages_are_listed(): void
    {
        $this->pro('فعال', 'electrician', 'Kabul');
        $this->pro('معلق', 'electrician', 'Kabul', ['actor' => ['status' => 'suspended']]);

        $this->assertCount(1, $this->ids());
    }

    public function test_verified_first_then_rating_then_completed_jobs(): void
    {
        $plain = $this->pro('الف', 'electrician', 'Kabul');
        $rated = $this->pro('ب', 'electrician', 'Kabul', ['stats' => ['completed_count' => 1, 'rating_count' => 3, 'rating_avg' => 4.2]]);
        $busy = $this->pro('ج', 'electrician', 'Kabul', ['stats' => ['completed_count' => 50, 'rating_count' => 3, 'rating_avg' => 4.2]]);
        $verified = $this->pro('د', 'electrician', 'Kabul', ['actor' => ['verified_at' => now()]]);
        $best = $this->pro('ه', 'electrician', 'Kabul', ['stats' => ['completed_count' => 0, 'rating_count' => 1, 'rating_avg' => 5]]);

        $this->assertSame([$verified, $best, $busy, $rated, $plain], $this->ids());
    }

    public function test_keyset_pages_cover_everything_once_in_the_same_order(): void
    {
        foreach (range(1, 12) as $i) {
            $this->pro("متخصص $i", 'electrician', 'Kabul', [
                'stats' => ['completed_count' => $i % 4, 'rating_count' => 1, 'rating_avg' => 3 + ($i % 3) * 0.5],
                'actor' => $i % 5 === 0 ? ['verified_at' => now()] : [],
            ]);
        }
        $full = $this->ids('limit=50');

        $collected = [];
        $cursor = null;
        $pages = 0;
        do {
            $response = $this->getJson('/api/v1/search/actors?limit=5'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            $collected = array_merge($collected, array_column($response->json('data'), 'id'));
            $cursor = $response->json('next_cursor');
            $pages++;
        } while ($cursor && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame($full, $collected);
        $this->assertCount(12, array_unique($collected));
    }

    public function test_the_last_page_has_no_cursor_and_an_exact_fit_does_not_invent_one(): void
    {
        foreach (range(1, 4) as $i) {
            $this->pro("م $i", 'electrician', 'Kabul');
        }

        $this->getJson('/api/v1/search/actors?limit=4')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('next_cursor', null);
        $this->assertNotNull($this->getJson('/api/v1/search/actors?limit=3')->json('next_cursor'));
    }

    public function test_pages_do_not_shift_when_a_new_actor_appears_in_between(): void
    {
        foreach (range(1, 6) as $i) {
            $this->pro("م $i", 'electrician', 'Kabul', ['stats' => ['completed_count' => $i, 'rating_count' => 1, 'rating_avg' => 4]]);
        }
        $first = $this->getJson('/api/v1/search/actors?limit=3')->assertOk();
        $this->pro('تازه', 'electrician', 'Kabul', ['stats' => ['completed_count' => 99, 'rating_count' => 1, 'rating_avg' => 5]]);

        $second = $this->getJson('/api/v1/search/actors?limit=3&cursor='.urlencode($first->json('next_cursor')))->assertOk();

        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_a_broken_cursor_is_a_clean_422(): void
    {
        $this->getJson('/api/v1/search/actors?cursor=garbage')->assertUnprocessable()->assertJsonPath('code', 'search.bad_cursor');
        $this->getJson('/api/v1/search/actors?cursor='.rtrim(strtr(base64_encode('[1,2]'), '+/', '-_'), '='))->assertUnprocessable();
    }

    public function test_limits_are_validated_and_there_is_no_offset(): void
    {
        $this->getJson('/api/v1/search/actors?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/search/actors?limit=500')->assertUnprocessable();
        $this->getJson('/api/v1/search/actors?city=notauuid')->assertUnprocessable();
        $this->getJson('/api/v1/search/actors?page=2&offset=10')->assertOk();   // unknown paging parameters are ignored
    }

    public function test_pages_without_a_profession_are_not_listed_until_they_have_one(): void
    {
        $this->business(null, ['display_name' => 'بدون حرفه']);
        $this->assertSame([], $this->ids());

        $page = $this->business(null, ['display_name' => 'با خدمت']);
        $this->service($page['actor'], ['status' => 'active']);
        $this->assertSame([$page['actor']], $this->ids());
    }

    public function test_results_carry_what_the_card_needs_and_no_search_text(): void
    {
        $this->pro('احمد', 'electrician', 'Kabul', ['actor' => ['contact_phone_e164' => '+93791234567']]);

        $item = $this->getJson('/api/v1/search/actors')->assertOk()->json('data.0');

        $this->assertSame('برقکار', $item['category']['name']);
        $this->assertSame('Kabul', $item['city']['name_en']);
        $this->assertSame('+93791234567', $item['contact_phone']);
        $this->assertArrayNotHasKey('search_text', $item);
        $this->assertArrayHasKey('stats', $item);
    }

    public function test_an_empty_query_lists_everyone_and_a_blank_one_is_the_same(): void
    {
        $this->pro('احمد', 'electrician', 'Kabul');

        $this->assertCount(1, $this->ids('q='));
        $this->assertCount(1, $this->ids('q='.urlencode('   ')));
    }
}
