<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceApiTest extends ApiTestCase
{
    private function body(array $o = []): array
    {
        return $o + [
            'title' => 'نصب برق ساختمان', 'category_id' => $this->category(), 'delivery_mode' => 'onsite',
            'pricing_type' => 'fixed', 'price_amount' => '500', 'currency' => 'AFN', 'price_unit' => 'hour',
        ];
    }

    private function create(array $page, array $o = [], ?string $as = null)
    {
        return $this->actingAsUser($as ?? $page['user'])->postJson("/api/v1/actors/{$page['actor']}/services", $this->body($o));
    }

    public function test_a_service_starts_as_a_draft_with_its_price_and_unit(): void
    {
        $page = $this->business();

        $this->create($page)->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('pricing.type', 'fixed')
            ->assertJsonPath('pricing.amount', '500.00')
            ->assertJsonPath('pricing.currency', 'AFN')
            ->assertJsonPath('pricing.unit', 'hour')
            ->assertJsonPath('delivery_mode', 'onsite');
    }

    public function test_prices_can_be_typed_with_persian_digits_and_separators(): void
    {
        $page = $this->business();

        $this->create($page, ['price_amount' => '۱٬۲۵۰'])->assertCreated()->assertJsonPath('pricing.amount', '1250.00');
        $this->create($page, ['price_amount' => '2,500.5'])->assertCreated()->assertJsonPath('pricing.amount', '2500.50');
    }

    public function test_a_negotiable_service_has_no_price(): void
    {
        $page = $this->business();

        $this->create($page, ['pricing_type' => 'negotiable', 'price_amount' => null, 'currency' => null, 'price_unit' => null])->assertCreated()->assertJsonPath('pricing.amount', null);
        $this->create($page, ['pricing_type' => 'negotiable'])->assertUnprocessable()->assertJsonValidationErrors('price_amount');
    }

    public function test_fixed_and_from_prices_need_all_three_fields(): void
    {
        $page = $this->business();

        foreach (['fixed', 'from'] as $type) {
            foreach (['price_amount', 'currency', 'price_unit'] as $missing) {
                $this->create($page, ['pricing_type' => $type, $missing => null])->assertUnprocessable()->assertJsonValidationErrors($missing);
            }
        }
        $this->create($page, ['pricing_type' => 'from'])->assertCreated();
    }

    public function test_values_are_validated(): void
    {
        $page = $this->business();

        $this->create($page, ['price_amount' => '0'])->assertUnprocessable();
        $this->create($page, ['price_amount' => 'abc'])->assertUnprocessable();
        $this->create($page, ['price_amount' => '1000000000000'])->assertUnprocessable();
        $this->create($page, ['currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->create($page, ['price_unit' => 'week'])->assertUnprocessable();
        $this->create($page, ['delivery_mode' => 'remote'])->assertUnprocessable();
        $this->create($page, ['title' => 'x'])->assertUnprocessable();
        $this->create($page, ['category_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->create($page, ['category_id' => null])->assertUnprocessable();
    }

    public function test_an_inactive_category_cannot_be_used(): void
    {
        $page = $this->business();
        $category = $this->category();
        DB::table('categories')->where('id', $category)->update(['is_active' => false]);

        $this->create($page, ['category_id' => $category])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_viewers_and_strangers_cannot_add_services(): void
    {
        $page = $this->business();

        $this->postJson("/api/v1/actors/{$page['actor']}/services", $this->body())->assertUnauthorized();
        $this->create($page, [], $this->member($page['actor'], 'viewer'))->assertForbidden();
        $this->create($page, [], $this->person()['user'])->assertForbidden();
        foreach (['editor', 'admin'] as $role) {
            $this->create($page, [], $this->member($page['actor'], $role))->assertCreated();
        }
    }

    public function test_only_active_services_are_public(): void
    {
        $page = $this->business();
        $draft = $this->create($page)->json('id');
        $active = $this->create($page, ['status' => 'active'])->json('id');

        $visitor = $this->person()['user'];
        $list = $this->actingAsUser($visitor)->getJson("/api/v1/actors/{$page['actor']}/services")->assertOk();
        $this->assertSame([$active], array_column($list->json('data'), 'id'));
        $this->actingAsUser($visitor)->getJson("/api/v1/services/$draft")->assertNotFound();
        $this->actingAsUser($visitor)->getJson("/api/v1/services/$active")->assertOk()->assertJsonPath('id', $active);
    }

    public function test_the_service_can_be_published_in_the_same_request(): void
    {
        $page = $this->business();

        $this->create($page, ['status' => 'active'])->assertCreated()->assertJsonPath('status', 'active');
    }

    public function test_members_see_every_service_of_their_page(): void
    {
        $page = $this->business();
        $draft = $this->create($page)->json('id');

        $this->actingAsUser($page['user'])->getJson("/api/v1/actors/{$page['actor']}/services?all=1")->assertJsonCount(1, 'data');
        $this->actingAsUser($page['user'])->getJson("/api/v1/services/$draft")->assertOk();
        $this->actingAsUser($this->person()['user'])->getJson("/api/v1/actors/{$page['actor']}/services?all=1")->assertJsonCount(0, 'data');
        $this->actingAsUser($this->person()['user'])->getJson("/api/v1/services/$draft")->assertNotFound();
    }

    public function test_publish_pause_and_archive(): void
    {
        $page = $this->business();
        $id = $this->create($page)->json('id');

        foreach (['active', 'paused', 'active'] as $status) {
            $this->actingAsUser($page['user'])->patchJson("/api/v1/services/$id", ['status' => $status])->assertOk()->assertJsonPath('status', $status);
        }
        $this->actingAsUser($page['user'])->deleteJson("/api/v1/services/$id")->assertNoContent();

        $this->assertDatabaseHas('services', ['id' => $id, 'status' => 'archived']);
        $stranger = $this->person()['user'];
        $this->actingAsUser($stranger)->getJson("/api/v1/services/$id")->assertNotFound();
        $this->actingAsUser($stranger)->getJson("/api/v1/actors/{$page['actor']}/services")->assertJsonCount(0, 'data');
    }

    public function test_an_archived_service_stays_for_old_orders(): void
    {
        $page = $this->business();
        $id = $this->create($page)->json('id');
        $order = $this->order($this->person()['actor'], $page['actor'], ['origin' => 'service', 'service_id' => $id]);

        $this->actingAsUser($page['user'])->deleteJson("/api/v1/services/$id")->assertNoContent();

        $this->assertDatabaseHas('orders', ['id' => $order, 'service_id' => $id]);
    }

    public function test_edit_changes_the_price_and_switching_to_negotiable_clears_it(): void
    {
        $page = $this->business();
        $id = $this->create($page)->json('id');
        $url = "/api/v1/services/$id";

        $this->actingAsUser($page['user'])->patchJson($url, ['price_amount' => '900', 'currency' => 'USD', 'price_unit' => 'day'])->assertOk()->assertJsonPath('pricing.amount', '900.00')->assertJsonPath('pricing.currency', 'USD');
        $this->actingAsUser($page['user'])->patchJson($url, ['pricing_type' => 'negotiable'])->assertOk()->assertJsonPath('pricing.amount', null)->assertJsonPath('pricing.currency', null);
        $this->actingAsUser($this->member($page['actor'], 'viewer'))->patchJson($url, ['title' => 'x y'])->assertForbidden();
        $this->actingAsUser($this->business()['user'])->patchJson($url, ['title' => 'هک شده'])->assertForbidden();
        $this->actingAsUser($this->business()['user'])->deleteJson($url)->assertForbidden();
    }

    public function test_the_search_text_of_service_and_page_follow_the_service(): void
    {
        $this->seed(DatabaseSeeder::class);
        $page = $this->business(null, ['display_name' => 'دکان']);
        $plumber = DB::table('categories')->where('slug', 'plumber')->value('id');

        $id = $this->create($page, ['title' => 'نصب توالت', 'category_id' => $plumber, 'status' => 'active'])->json('id');
        $this->actingAsUser($page['user'])->patchJson("/api/v1/services/$id", ['status' => 'active'])->assertOk();

        $service = DB::table('services')->where('id', $id)->value('search_text');
        $this->assertStringContainsString('نصب توالت', $service);
        $this->assertStringContainsString('پلمبر', $service);
        $this->assertStringContainsString('نل ساز', $service);   // alias
        $this->assertStringContainsString('plumber', $service);
        $this->assertStringContainsString('نصب توالت', DB::table('actors')->where('id', $page['actor'])->value('search_text'));

        $this->actingAsUser($page['user'])->deleteJson("/api/v1/services/$id")->assertNoContent();
        $this->assertStringNotContainsString('نصب توالت', DB::table('actors')->where('id', $page['actor'])->value('search_text'));
    }
}
