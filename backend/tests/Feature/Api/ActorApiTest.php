<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActorApiTest extends ApiTestCase
{
    // ---------------------------------------------------------------- actor context

    public function test_a_request_acting_as_an_actor_needs_the_header(): void
    {
        $person = $this->person();

        $this->actingAsUser($person['user'])->getJson('/api/v1/context')->assertStatus(400)->assertJsonPath('code', 'actor.header_missing');
        $this->actingAsUser($person['user'])->withHeader('X-Actor-Id', 'nope')->getJson('/api/v1/context')->assertStatus(400);
    }

    public function test_a_user_can_act_only_as_actors_they_are_a_member_of(): void
    {
        $me = $this->person();
        $page = $this->business($me);
        $stranger = $this->business();

        $this->actingAsUser($me['user'], $me['actor'])->getJson('/api/v1/context')->assertOk()->assertJsonPath('role', 'owner');
        $this->actingAsUser($me['user'], $page['actor'])->getJson('/api/v1/context')->assertOk()->assertJsonPath('is_primary', true);
        $this->actingAsUser($me['user'], $stranger['actor'])->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'actor.forbidden');
        $this->actingAsUser($me['user'], (string) Str::uuid())->getJson('/api/v1/context')->assertForbidden();
    }

    public function test_the_context_needs_login(): void
    {
        $this->getJson('/api/v1/context')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- create and show

    public function test_creating_a_page_makes_the_creator_its_primary_owner(): void
    {
        $this->seed(DatabaseSeeder::class);
        $me = $this->person();
        $category = DB::table('categories')->where('slug', 'electrician')->value('id');
        $city = DB::table('cities')->where('name_en', 'Kabul')->value('id');

        $response = $this->actingAsUser($me['user'])->postJson('/api/v1/actors', [
            'display_name' => 'برق احمد', 'category_id' => $category, 'city_id' => $city,
            'contact_phone' => '۰۷۹۱۲۳۴۵۶۷', 'whatsapp' => '0791234567', 'bio' => 'برقکار با تجربه',
        ])->assertCreated()->assertJsonPath('type', 'business')->assertJsonPath('contact_phone', '+93791234567');

        $id = $response->json('id');
        $this->assertDatabaseHas('actor_members', ['actor_id' => $id, 'user_id' => $me['user'], 'role' => 'owner', 'is_primary' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'actor.created', 'actor_id' => $id]);
        $this->assertStringContainsString('برقکار', DB::table('actors')->where('id', $id)->value('search_text'));
    }

    public function test_one_user_can_run_several_pages(): void
    {
        $me = $this->person();

        foreach (['دکان یک', 'دکان دو'] as $name) {
            $this->actingAsUser($me['user'])->postJson('/api/v1/actors', ['display_name' => $name])->assertCreated();
        }

        $this->actingAsUser($me['user'])->getJson('/api/v1/me')->assertJsonCount(3, 'actors');
    }

    public function test_page_data_is_validated(): void
    {
        $me = $this->person();

        $this->actingAsUser($me['user'])->postJson('/api/v1/actors', [])->assertUnprocessable()->assertJsonValidationErrors('display_name');
        $this->actingAsUser($me['user'])->postJson('/api/v1/actors', ['display_name' => 'x'])->assertUnprocessable();
        $this->actingAsUser($me['user'])->postJson('/api/v1/actors', ['display_name' => 'دکان', 'category_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->actingAsUser($me['user'])->postJson('/api/v1/actors', ['display_name' => 'دکان', 'contact_phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('contact_phone');
    }

    public function test_creating_a_page_needs_login(): void
    {
        $this->postJson('/api/v1/actors', ['display_name' => 'دکان'])->assertUnauthorized();
    }

    public function test_the_public_profile_shows_contact_services_and_stats_without_login(): void
    {
        $page = $this->business(null, ['display_name' => 'دکان من', 'contact_phone_e164' => '+93791234567', 'whatsapp_e164' => '+93791234567']);
        $active = $this->service($page['actor'], ['status' => 'active', 'title' => 'خدمت فعال']);
        $this->service($page['actor'], ['status' => 'draft']);
        $this->service($page['actor'], ['status' => 'archived']);
        DB::table('actor_stats')->insert(['actor_id' => $page['actor'], 'completed_count' => 4, 'rating_count' => 2, 'rating_avg' => 4.5]);

        $this->getJson('/api/v1/actors/'.$page['actor'])->assertOk()
            ->assertJsonPath('display_name', 'دکان من')
            ->assertJsonPath('contact_phone', '+93791234567')
            ->assertJsonPath('whatsapp', '+93791234567')
            ->assertJsonPath('stats.rating_avg', 4.5)
            ->assertJsonPath('stats.completed_count', 4)
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.id', $active)
            ->assertJsonMissingPath('search_text');
    }

    public function test_internal_ids_and_user_data_never_leak_on_the_public_profile(): void
    {
        $page = $this->business();

        $json = $this->getJson('/api/v1/actors/'.$page['actor'])->assertOk()->getContent();

        $this->assertStringNotContainsString($page['user'], $json);
        $this->assertStringNotContainsString('personal_user_id', $json);
        $this->assertStringNotContainsString('deletion', $json);
    }

    public function test_suspended_and_deleted_pages_are_not_public(): void
    {
        $suspended = $this->business(null, ['status' => 'suspended']);

        $this->getJson('/api/v1/actors/'.$suspended['actor'])->assertNotFound();
        $this->getJson('/api/v1/actors/'.Str::uuid())->assertNotFound();
    }

    // ---------------------------------------------------------------- update

    public function test_editors_and_above_can_edit_the_profile_but_viewers_cannot(): void
    {
        $page = $this->business();
        $users = ['owner' => $page['user'], 'admin' => $this->member($page['actor'], 'admin'), 'editor' => $this->member($page['actor'], 'editor'), 'viewer' => $this->member($page['actor'], 'viewer')];
        $stranger = $this->person();

        foreach (['owner', 'admin', 'editor'] as $role) {
            $this->actingAsUser($users[$role])->patchJson('/api/v1/actors/'.$page['actor'], ['bio' => "by $role"])->assertOk()->assertJsonPath('bio', "by $role");
        }
        $this->actingAsUser($users['viewer'])->patchJson('/api/v1/actors/'.$page['actor'], ['bio' => 'x'])->assertForbidden();
        $this->actingAsUser($stranger['user'])->patchJson('/api/v1/actors/'.$page['actor'], ['bio' => 'x'])->assertForbidden();
        $this->assertSame('by editor', DB::table('actors')->where('id', $page['actor'])->value('bio'));
    }

    public function test_editing_the_name_refreshes_the_search_text(): void
    {
        $page = $this->business(null, ['display_name' => 'اسم قدیم']);

        $this->actingAsUser($page['user'])->patchJson('/api/v1/actors/'.$page['actor'], ['display_name' => 'اسم جدید'])->assertOk();

        $this->assertSame('اسم جدید', DB::table('actors')->where('id', $page['actor'])->value('search_text'));
    }

    public function test_a_person_can_edit_their_own_profile_with_the_three_steps(): void
    {
        $this->seed(DatabaseSeeder::class);
        $me = $this->person();

        $this->actingAsUser($me['user'])->patchJson('/api/v1/actors/'.$me['actor'], [
            'display_name' => 'احمد', 'category_id' => DB::table('categories')->where('slug', 'plumber')->value('id'),
            'city_id' => DB::table('cities')->where('name_en', 'Herat')->value('id'),
        ])->assertOk()->assertJsonPath('city.name_en', 'Herat')->assertJsonPath('category.slug', 'plumber');
    }

    public function test_the_type_cannot_be_changed_through_the_api(): void
    {
        $me = $this->person();

        $this->actingAsUser($me['user'])->patchJson('/api/v1/actors/'.$me['actor'], ['type' => 'business', 'personal_user_id' => null])->assertOk();
        $this->assertSame('person', DB::table('actors')->where('id', $me['actor'])->value('type'));
    }

    public function test_service_areas_are_replaced_as_a_list(): void
    {
        $page = $this->business();
        [$a, $b, $c] = [$this->city(), $this->city(), $this->city()];

        $this->actingAsUser($page['user'])->putJson("/api/v1/actors/{$page['actor']}/service-areas", ['city_ids' => [$a, $b]])->assertOk()->assertJsonCount(2, 'service_areas');
        $this->actingAsUser($page['user'])->putJson("/api/v1/actors/{$page['actor']}/service-areas", ['city_ids' => [$c]])->assertOk()->assertJsonCount(1, 'service_areas');
        $this->assertSame([$c], DB::table('actor_service_areas')->where('actor_id', $page['actor'])->pluck('city_id')->all());
        $this->actingAsUser($page['user'])->putJson("/api/v1/actors/{$page['actor']}/service-areas", ['city_ids' => []])->assertOk()->assertJsonCount(0, 'service_areas');
        $this->actingAsUser($page['user'])->putJson("/api/v1/actors/{$page['actor']}/service-areas", ['city_ids' => [$a, $a]])->assertUnprocessable();
        $this->actingAsUser($this->member($page['actor'], 'viewer'))->putJson("/api/v1/actors/{$page['actor']}/service-areas", ['city_ids' => [$a]])->assertForbidden();
    }

    // ---------------------------------------------------------------- deletion

    public function test_a_co_owner_requests_deletion_and_only_the_primary_owner_confirms(): void
    {
        $page = $this->business();
        $co = $this->member($page['actor'], 'owner');
        $url = "/api/v1/actors/{$page['actor']}";

        $this->actingAsUser($co)->postJson("$url/deletion-confirm")->assertForbidden();
        $this->actingAsUser($page['user'])->postJson("$url/deletion-confirm")->assertStatus(409)->assertJsonPath('code', 'actor.deletion_not_requested');

        $this->actingAsUser($co)->postJson("$url/deletion-request")->assertNoContent();
        $this->actingAsUser($co)->postJson("$url/deletion-confirm")->assertForbidden();
        $this->actingAsUser($page['user'])->postJson("$url/deletion-confirm")->assertNoContent();

        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'actor.deleted', 'actor_id' => $page['actor']]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_admins_editors_and_strangers_cannot_ask_for_deletion(): void
    {
        $page = $this->business();

        foreach (['admin', 'editor', 'viewer'] as $role) {
            $this->actingAsUser($this->member($page['actor'], $role))->postJson("/api/v1/actors/{$page['actor']}/deletion-request")->assertForbidden();
        }
        $this->actingAsUser($this->person()['user'])->postJson("/api/v1/actors/{$page['actor']}/deletion-request")->assertForbidden();
    }

    public function test_a_page_with_open_orders_cannot_be_deleted(): void
    {
        $page = $this->business();
        $this->order($this->person()['actor'], $page['actor']);
        $url = "/api/v1/actors/{$page['actor']}";

        $this->actingAsUser($page['user'])->postJson("$url/deletion-request")->assertNoContent();
        $this->actingAsUser($page['user'])->postJson("$url/deletion-confirm")->assertStatus(409)->assertJsonPath('code', 'actor.has_open_orders');
        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'active']);
    }

    public function test_a_deletion_request_can_be_cancelled(): void
    {
        $page = $this->business();
        $url = "/api/v1/actors/{$page['actor']}";

        $this->actingAsUser($page['user'])->postJson("$url/deletion-request")->assertNoContent();
        $this->actingAsUser($page['user'])->deleteJson("$url/deletion-request")->assertNoContent();
        $this->actingAsUser($page['user'])->postJson("$url/deletion-confirm")->assertStatus(409);
    }

    public function test_a_personal_actor_cannot_be_deleted_through_the_page_flow(): void
    {
        $me = $this->person();

        $this->actingAsUser($me['user'])->postJson("/api/v1/actors/{$me['actor']}/deletion-request")->assertForbidden();
    }
}
