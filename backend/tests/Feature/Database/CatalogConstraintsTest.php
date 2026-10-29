<?php

namespace Tests\Feature\Database;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

class CatalogConstraintsTest extends DatabaseTestCase
{
    // ---------------------------------------------------------------- seeds

    public function test_seeders_fill_provinces_cities_and_categories(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(34, DB::table('provinces')->count());
        $this->assertGreaterThanOrEqual(34, DB::table('cities')->count());
        $this->assertGreaterThan(30, DB::table('categories')->count());
        $this->assertSame(0, DB::table('provinces')->whereNull('name')->count());
    }

    public function test_every_province_has_a_city_and_the_capital_sorts_first(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('provinces')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cities')->whereColumn('cities.province_id', 'provinces.id'))->count());
        $kabul = DB::table('cities')->where('name', 'کابل')->first();
        $this->assertSame(0, $kabul->sort_order);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [DB::table('provinces')->count(), DB::table('cities')->count(), DB::table('categories')->count()];
        $ids = DB::table('categories')->orderBy('slug')->pluck('id')->all();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [DB::table('provinces')->count(), DB::table('cities')->count(), DB::table('categories')->count()]);
        $this->assertSame($ids, DB::table('categories')->orderBy('slug')->pluck('id')->all());
    }

    public function test_categories_form_a_two_level_tree_with_aliases(): void
    {
        $this->seed(DatabaseSeeder::class);

        $electrician = DB::table('categories')->where('slug', 'electrician')->first();
        $this->assertNotNull($electrician->parent_id);
        $this->assertSame('برقکار', $electrician->name);
        $this->assertSame('Electrician', $electrician->name_en);
        $this->assertNull(DB::table('categories')->where('id', $electrician->parent_id)->value('parent_id'));
    }

    public function test_category_aliases_are_searchable_with_the_gin_index(): void
    {
        $this->seed(DatabaseSeeder::class);

        // "نل ساز" is an alias of the plumber, "نجار" the name of the carpenter.
        $hit = DB::select("select slug from categories where aliases @> ARRAY['نل ساز']::text[]");
        $this->assertSame(['plumber'], array_column($hit, 'slug'));
    }

    public function test_alias_letters_are_stored_normalized(): void
    {
        $this->seed(DatabaseSeeder::class);

        $arabicLetters = DB::select("select slug from categories where array_to_string(aliases, ' ') ~ '[يك]'");
        $this->assertSame([], $arabicLetters);
    }

    // ---------------------------------------------------------------- geography and categories

    public function test_city_names_are_unique_within_a_province_but_not_across(): void
    {
        $province = $this->province();
        $this->insert('cities', ['province_id' => $province, 'name' => 'کابل']);
        $this->insert('cities', ['province_id' => $this->province(), 'name' => 'کابل']);

        $this->assertRejected(fn () => $this->insert('cities', ['province_id' => $province, 'name' => 'کابل']), 'cities_unique_in_province');
    }

    public function test_a_province_with_cities_cannot_be_deleted(): void
    {
        $province = $this->province();
        $this->city($province);

        $this->assertRejected(fn () => DB::table('provinces')->where('id', $province)->delete(), 'cities_province_id_fkey');
    }

    public function test_dari_name_is_required_and_other_languages_are_optional(): void
    {
        $this->insert('provinces', ['code' => 'x', 'name' => 'الف']);
        $this->assertRejected(fn () => $this->insert('provinces', ['code' => 'y', 'name' => ' ']), 'provinces_name_not_blank');
        $this->assertRejected(fn () => $this->insert('provinces', ['code' => 'z']), 'null value');
        $this->assertRejected(fn () => $this->insert('categories', ['slug' => 'q', 'name' => '']), 'categories_name_not_blank');
    }

    public function test_category_slug_is_unique_and_a_category_cannot_be_its_own_parent(): void
    {
        $id = $this->category();
        $slug = DB::table('categories')->where('id', $id)->value('slug');

        $this->assertRejected(fn () => $this->insert('categories', ['slug' => $slug, 'name' => 'x']), 'categories_slug_key');
        $this->assertRejected(fn () => DB::table('categories')->where('id', $id)->update(['parent_id' => $id]), 'categories_not_own_parent');
    }

    // ---------------------------------------------------------------- service areas

    public function test_a_city_is_listed_once_per_actor_service_area(): void
    {
        $page = $this->business();
        $city = $this->city();
        DB::table('actor_service_areas')->insert(['actor_id' => $page['actor'], 'city_id' => $city]);

        $this->assertRejected(fn () => DB::table('actor_service_areas')->insert(['actor_id' => $page['actor'], 'city_id' => $city]), 'actor_service_areas_pkey');
    }

    // ---------------------------------------------------------------- services

    private function serviceRow(string $actorId, array $o = []): array
    {
        return $o + ['actor_id' => $actorId, 'category_id' => $this->category(), 'title' => 'x', 'pricing_type' => 'fixed',
            'price_amount' => 50, 'currency' => 'USD', 'price_unit' => 'day'];
    }

    public function test_fixed_and_from_prices_need_amount_currency_and_unit(): void
    {
        $actor = $this->business()['actor'];

        foreach (['fixed', 'from'] as $type) {
            $this->insert('services', $this->serviceRow($actor, ['pricing_type' => $type]));
            foreach (['price_amount', 'currency', 'price_unit'] as $missing) {
                $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['pricing_type' => $type, $missing => null])), 'services_price_required');
            }
        }
    }

    public function test_a_negotiable_service_has_no_amount(): void
    {
        $actor = $this->business()['actor'];

        $this->insert('services', $this->serviceRow($actor, ['pricing_type' => 'negotiable', 'price_amount' => null, 'currency' => null, 'price_unit' => null]));
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['pricing_type' => 'negotiable'])), 'services_negotiable_has_no_amount');
    }

    public function test_price_values_are_restricted(): void
    {
        $actor = $this->business()['actor'];

        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['currency' => 'EUR'])), 'services_currency_valid');
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['price_unit' => 'week'])), 'services_price_unit_valid');
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['price_amount' => 0])), 'services_price_required');
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['pricing_type' => 'cheap'])), 'services_pricing_type_valid');
    }

    public function test_amount_keeps_two_decimals_and_fourteen_digits(): void
    {
        $actor = $this->business()['actor'];
        $id = $this->insert('services', $this->serviceRow($actor, ['price_amount' => '12345.678']));

        $this->assertEquals('12345.68', DB::table('services')->where('id', $id)->value('price_amount'));
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['price_amount' => '1000000000000.00'])), 'numeric field overflow');
    }

    public function test_delivery_mode_and_status_are_restricted(): void
    {
        $actor = $this->business()['actor'];

        foreach (['onsite', 'online', 'both'] as $mode) {
            $this->insert('services', $this->serviceRow($actor, ['delivery_mode' => $mode]));
        }
        foreach (['draft', 'active', 'paused', 'archived'] as $status) {
            $this->insert('services', $this->serviceRow($actor, ['status' => $status]));
        }
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['delivery_mode' => 'remote'])), 'services_delivery_mode_valid');
        $this->assertRejected(fn () => $this->insert('services', $this->serviceRow($actor, ['status' => 'deleted'])), 'services_status_valid');
    }

    public function test_portfolio_items_can_only_point_at_a_service_of_the_same_actor(): void
    {
        $page = $this->business();
        $other = $this->business();
        $service = $this->service($page['actor']);

        $this->insert('portfolio_items', ['actor_id' => $page['actor'], 'service_id' => $service, 'title' => 'کار من']);
        $this->insert('portfolio_items', ['actor_id' => $page['actor'], 'title' => 'بدون خدمت']);
        $this->assertRejected(fn () => $this->insert('portfolio_items', ['actor_id' => $other['actor'], 'service_id' => $service, 'title' => 'x']), 'portfolio_service_same_actor');
    }
}
