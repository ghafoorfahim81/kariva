<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NeedsApiTest extends ApiTestCase
{
    private array $customer;

    private array $pro1;

    private array $pro2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = $this->person();
        $this->pro1 = $this->business();
        $this->pro2 = $this->business();
    }

    private function as(array $who): static
    {
        return $this->actingAsUser($who['user'], $who['actor']);
    }

    private function makeNeed(array $o = []): string
    {
        return $this->as($this->customer)->postJson('/api/v1/needs', $o + ['kind' => 'service', 'category_id' => $this->category(), 'title' => 'نصب سیم‌کشی', 'description' => 'خانه‌ی دو طبقه'])->assertCreated()->json('id');
    }

    private function makeOffer(string $need, array $who, array $o = [])
    {
        return $this->as($who)->postJson("/api/v1/needs/$need/offers", $o + ['amount' => '8000', 'currency' => 'AFN', 'price_unit' => 'project', 'days' => 5, 'message' => 'آماده‌ام']);
    }

    // ---------------------------------------------------------------- needs

    public function test_a_need_is_created_open_with_an_expiry(): void
    {
        $res = $this->as($this->customer)->postJson('/api/v1/needs', ['kind' => 'service', 'category_id' => $this->category(), 'title' => 'نصب سیم‌کشی'])->assertCreated();

        $res->assertJsonPath('status', 'open')->assertJsonPath('kind', 'service')->assertJsonPath('actor.id', $this->customer['actor'])->assertJsonPath('is_mine', true);
        $this->assertEqualsWithDelta(30 * 86400, strtotime($res->json('expires_at')) - time(), 120);
    }

    public function test_need_data_is_validated_and_viewers_cannot_post(): void
    {
        $this->as($this->customer)->postJson('/api/v1/needs', [])->assertUnprocessable();
        $this->as($this->customer)->postJson('/api/v1/needs', ['kind' => 'gig', 'category_id' => $this->category(), 'title' => 'یک دو'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->as($this->customer)->postJson('/api/v1/needs', ['kind' => 'job', 'category_id' => (string) Str::uuid(), 'title' => 'یک دو'])->assertUnprocessable();
        $page = $this->business($this->customer);
        $viewer = $this->member($page['actor'], 'viewer');
        $this->actingAsUser($viewer, $page['actor'])->postJson('/api/v1/needs', ['kind' => 'job', 'category_id' => $this->category(), 'title' => 'یک دو'])->assertForbidden();
    }

    public function test_the_public_board_lists_open_needs_newest_first_with_filters(): void
    {
        $cat = $this->category();
        $city = $this->city();
        $old = $this->makeNeed(['category_id' => $cat, 'city_id' => $city, 'title' => 'قدیمی']);
        $this->travel(1)->minute();
        $new = $this->makeNeed(['category_id' => $cat, 'title' => 'تازه', 'kind' => 'job']);
        $closed = $this->makeNeed();
        $this->as($this->customer)->postJson("/api/v1/needs/$closed/close")->assertOk();
        DB::table('needs')->insert(['id' => (string) Str::uuid(), 'actor_id' => $this->customer['actor'], 'category_id' => $cat, 'kind' => 'service', 'title' => 'منقضی', 'expires_at' => now()->subDay()]);

        $this->assertSame([$new, $old], array_column($this->getJson('/api/v1/needs?category='.$cat)->assertOk()->json('data'), 'id'));
        $this->assertSame([$new], array_column($this->getJson('/api/v1/needs?kind=job')->json('data'), 'id'));
        $this->assertSame([$old], array_column($this->getJson("/api/v1/needs?city=$city")->json('data'), 'id'));
    }

    public function test_the_board_is_paged_and_filters_by_category_tree(): void
    {
        $root = $this->category();
        $child = $this->category($root);
        $a = $this->makeNeed(['category_id' => $child, 'title' => 'الف']);
        $this->travel(1)->minute();
        $b = $this->makeNeed(['category_id' => $child, 'title' => 'بب']);

        $first = $this->getJson("/api/v1/needs?category=$root&limit=1")->assertOk();
        $second = $this->getJson("/api/v1/needs?category=$root&limit=1&cursor=".$first->json('next_cursor'))->assertOk();

        $this->assertSame([$b], array_column($first->json('data'), 'id'));
        $this->assertSame([$a], array_column($second->json('data'), 'id'));
        $this->assertNull($second->json('next_cursor'));
        $this->getJson('/api/v1/needs?cursor=bad')->assertUnprocessable()->assertJsonPath('code', 'needs.bad_cursor');
    }

    public function test_a_closed_need_is_visible_only_to_its_owner(): void
    {
        $id = $this->makeNeed();
        $this->as($this->customer)->postJson("/api/v1/needs/$id/close")->assertOk()->assertJsonPath('status', 'closed');

        $this->as($this->customer)->getJson("/api/v1/needs/$id")->assertOk();
        $this->as($this->pro1)->getJson("/api/v1/needs/$id")->assertNotFound();
        $this->as($this->customer)->postJson("/api/v1/needs/$id/close")->assertStatus(409);
    }

    public function test_my_needs_lists_all_statuses_with_pending_offer_counts(): void
    {
        $open = $this->makeNeed();
        $closed = $this->makeNeed();
        $this->makeOffer($open, $this->pro1)->assertCreated();
        $this->makeOffer($open, $this->pro2)->assertCreated();
        $this->as($this->customer)->postJson("/api/v1/needs/$closed/close")->assertOk();

        $rows = collect($this->as($this->customer)->getJson('/api/v1/my/needs')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rows[$open]['pending_offers']);
        $this->assertSame('closed', $rows[$closed]['status']);
        $this->assertSame([], $this->as($this->pro1)->getJson('/api/v1/my/needs')->json('data'));
    }

    public function test_only_the_owner_closes_a_need(): void
    {
        $id = $this->makeNeed();

        $this->as($this->pro1)->postJson("/api/v1/needs/$id/close")->assertForbidden()->assertJsonPath('code', 'need.wrong_side');
    }

    // ---------------------------------------------------------------- offers

    public function test_a_specialist_sends_an_offer_and_the_owner_is_notified(): void
    {
        $id = $this->makeNeed();

        $this->makeOffer($id, $this->pro1, ['amount' => '۸٬۰۰۰'])->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('amount', '8000.00')->assertJsonPath('provider.id', $this->pro1['actor']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer['user'], 'type' => 'offer.received']);
    }

    public function test_one_offer_per_specialist_and_not_on_your_own_need(): void
    {
        $id = $this->makeNeed();
        $this->makeOffer($id, $this->pro1)->assertCreated();

        $this->makeOffer($id, $this->pro1)->assertStatus(409)->assertJsonPath('code', 'offer.already_made');
        $this->makeOffer($id, $this->customer)->assertUnprocessable()->assertJsonPath('code', 'offer.own_need');
    }

    public function test_a_job_opportunity_gets_no_offers(): void
    {
        $id = $this->makeNeed(['kind' => 'job']);

        $this->makeOffer($id, $this->pro1)->assertUnprocessable()->assertJsonPath('code', 'need.offers_not_allowed');
    }

    public function test_offers_need_an_open_need_and_valid_values(): void
    {
        $id = $this->makeNeed();
        $this->makeOffer($id, $this->pro1, ['amount' => '0'])->assertUnprocessable();
        $this->makeOffer($id, $this->pro1, ['currency' => 'EUR'])->assertUnprocessable();
        $this->makeOffer($id, $this->pro1, ['price_unit' => 'week'])->assertUnprocessable();

        $this->as($this->customer)->postJson("/api/v1/needs/$id/close")->assertOk();
        $this->makeOffer($id, $this->pro2)->assertStatus(409)->assertJsonPath('code', 'need.not_open');
    }

    public function test_an_expired_need_takes_no_offers(): void
    {
        $id = $this->makeNeed();
        DB::table('needs')->where('id', $id)->update(['expires_at' => now()->subMinute()]);

        $this->makeOffer($id, $this->pro1)->assertStatus(409);
        $this->getJson("/api/v1/needs/$id")->assertNotFound();
    }

    public function test_the_owner_sees_all_offers_and_a_specialist_only_its_own(): void
    {
        $id = $this->makeNeed();
        $this->makeOffer($id, $this->pro1)->assertCreated();
        $this->makeOffer($id, $this->pro2)->assertCreated();

        $this->assertCount(2, $this->as($this->customer)->getJson("/api/v1/needs/$id/offers")->assertOk()->json('data'));
        $mine = $this->as($this->pro1)->getJson("/api/v1/needs/$id/offers")->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($this->pro1['actor'], $mine[0]['provider']['id']);
    }

    public function test_a_specialist_can_withdraw_a_pending_offer(): void
    {
        $id = $this->makeNeed();
        $offer = $this->makeOffer($id, $this->pro1)->json('id');

        $this->as($this->pro2)->postJson("/api/v1/offers/$offer/withdraw")->assertForbidden();
        $this->as($this->pro1)->postJson("/api/v1/offers/$offer/withdraw")->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->as($this->pro1)->postJson("/api/v1/offers/$offer/withdraw")->assertStatus(409);
    }

    public function test_the_owner_can_reject_an_offer(): void
    {
        $id = $this->makeNeed();
        $offer = $this->makeOffer($id, $this->pro1)->json('id');

        $this->as($this->pro1)->postJson("/api/v1/offers/$offer/reject")->assertForbidden();
        $this->as($this->customer)->postJson("/api/v1/offers/$offer/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->pro1['user'], 'type' => 'offer.rejected']);
    }

    // ---------------------------------------------------------------- accepting

    public function test_accepting_an_offer_starts_an_accepted_order_and_closes_the_need(): void
    {
        $id = $this->makeNeed(['title' => 'سیم‌کشی ویلا', 'description' => 'سه اتاق']);
        $winner = $this->makeOffer($id, $this->pro1, ['amount' => '9000', 'currency' => 'USD', 'price_unit' => 'day'])->json('id');
        $loser = $this->makeOffer($id, $this->pro2)->json('id');

        $order = $this->as($this->customer)->postJson("/api/v1/offers/$winner/accept")->assertCreated()
            ->assertJsonPath('origin', 'offer')->assertJsonPath('status', 'accepted')->assertJsonPath('title', 'سیم‌کشی ویلا')
            ->assertJsonPath('customer.id', $this->customer['actor'])->assertJsonPath('provider.id', $this->pro1['actor'])
            ->assertJsonPath('agreed.amount', '9000.00')->assertJsonPath('agreed.currency', 'USD')->assertJsonPath('agreed.unit', 'day');

        $this->assertDatabaseHas('offers', ['id' => $winner, 'status' => 'accepted']);
        $this->assertDatabaseHas('offers', ['id' => $loser, 'status' => 'rejected']);
        $this->assertDatabaseHas('needs', ['id' => $id, 'status' => 'closed']);
        $this->assertDatabaseHas('orders', ['id' => $order->json('id'), 'offer_id' => $winner, 'need_id' => $id]);
        $this->assertNotNull($order->json('conversation_id'));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->pro1['user'], 'type' => 'offer.accepted']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->pro2['user'], 'type' => 'offer.rejected']);
        $this->assertSame(['created_from_offer'], array_column($order->json('events'), 'event'));
    }

    public function test_the_order_from_an_offer_follows_the_normal_flow(): void
    {
        $id = $this->makeNeed();
        $offer = $this->makeOffer($id, $this->pro1)->json('id');
        $order = $this->as($this->customer)->postJson("/api/v1/offers/$offer/accept")->json('id');

        $this->as($this->pro1)->postJson("/api/v1/orders/$order/done")->assertOk();
        $this->as($this->customer)->postJson("/api/v1/orders/$order/confirm")->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_only_the_owner_accepts_and_only_once(): void
    {
        $id = $this->makeNeed();
        $a = $this->makeOffer($id, $this->pro1)->json('id');
        $b = $this->makeOffer($id, $this->pro2)->json('id');

        $this->as($this->pro1)->postJson("/api/v1/offers/$a/accept")->assertForbidden();
        $this->as($this->customer)->postJson("/api/v1/offers/$a/accept")->assertCreated();
        $this->as($this->customer)->postJson("/api/v1/offers/$b/accept")->assertStatus(409);
        $this->assertSame(1, DB::table('orders')->where('need_id', $id)->count());
    }

    public function test_a_withdrawn_or_rejected_offer_cannot_be_accepted(): void
    {
        $id = $this->makeNeed();
        $a = $this->makeOffer($id, $this->pro1)->json('id');
        $this->as($this->pro1)->postJson("/api/v1/offers/$a/withdraw")->assertOk();

        $this->as($this->customer)->postJson("/api/v1/offers/$a/accept")->assertStatus(409)->assertJsonPath('code', 'offer.not_pending');
    }

    public function test_a_suspended_provider_cannot_be_hired(): void
    {
        $id = $this->makeNeed();
        $offer = $this->makeOffer($id, $this->pro1)->json('id');
        DB::table('actors')->where('id', $this->pro1['actor'])->update(['status' => 'suspended']);

        $this->as($this->customer)->postJson("/api/v1/offers/$offer/accept")->assertUnprocessable()->assertJsonPath('code', 'order.actor_unavailable');
        $this->assertDatabaseHas('needs', ['id' => $id, 'status' => 'open']);
    }

    public function test_closing_a_need_rejects_pending_offers(): void
    {
        $id = $this->makeNeed();
        $offer = $this->makeOffer($id, $this->pro1)->json('id');

        $this->as($this->customer)->postJson("/api/v1/needs/$id/close")->assertOk();

        $this->assertDatabaseHas('offers', ['id' => $offer, 'status' => 'rejected']);
    }

    public function test_acting_as_a_page_works_for_editors_only(): void
    {
        $page = $this->business($this->customer);
        $viewer = $this->member($page['actor'], 'viewer');
        $editor = $this->member($page['actor'], 'editor');

        $this->actingAsUser($viewer, $page['actor'])->postJson('/api/v1/needs', ['kind' => 'service', 'category_id' => $this->category(), 'title' => 'کار صفحه'])->assertForbidden();
        $this->actingAsUser($editor, $page['actor'])->postJson('/api/v1/needs', ['kind' => 'service', 'category_id' => $this->category(), 'title' => 'کار صفحه'])->assertCreated()->assertJsonPath('actor.id', $page['actor']);
    }

    public function test_needs_and_offers_need_login(): void
    {
        $this->postJson('/api/v1/needs', [])->assertUnauthorized();
        $this->getJson('/api/v1/my/needs')->assertUnauthorized();
        $this->getJson('/api/v1/needs')->assertOk();
    }
}
