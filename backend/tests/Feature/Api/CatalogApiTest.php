<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;

class CatalogApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_categories_come_as_a_tree_with_all_three_languages(): void
    {
        $response = $this->getJson('/api/v1/categories')->assertOk();

        $roots = collect($response->json('data'));
        $this->assertCount(10, $roots);
        $construction = $roots->firstWhere('slug', 'construction');
        $this->assertSame('ساختمان و تعمیرات', $construction['name']);
        $this->assertSame('Construction & Repair', $construction['name_en']);
        $electrician = collect($construction['children'])->firstWhere('slug', 'electrician');
        $this->assertSame('برقکار', $electrician['name']);
        $this->assertSame($construction['id'], $electrician['parent_id']);
    }

    public function test_inactive_categories_are_hidden(): void
    {
        \DB::table('categories')->where('slug', 'electrician')->update(['is_active' => false]);

        $slugs = collect($this->getJson('/api/v1/categories')->json('data.0.children'))->pluck('slug');
        $this->assertNotContains('electrician', $slugs);
    }

    public function test_provinces_carry_their_cities(): void
    {
        $data = collect($this->getJson('/api/v1/provinces')->assertOk()->json('data'));

        $this->assertCount(34, $data);
        $kabul = $data->firstWhere('code', 'kabul');
        $this->assertSame('کابل', $kabul['name']);
        $this->assertSame('Kabul', $kabul['cities'][0]['name_en']);
    }

    public function test_the_city_list_is_flat_and_cacheable(): void
    {
        $response = $this->getJson('/api/v1/cities')->assertOk();

        $this->assertGreaterThan(34, count($response->json('data')));
        $this->assertStringContainsString('max-age', $response->headers->get('Cache-Control'));
    }

    public function test_reference_data_needs_no_login(): void
    {
        foreach (['categories', 'provinces', 'cities'] as $path) {
            $this->getJson("/api/v1/$path")->assertOk();
        }
    }
}
