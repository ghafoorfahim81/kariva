<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrdersApiTest extends ApiTestCase
{
    private array $customer;

    private array $provider;

    private string $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = $this->person();
        $this->provider = $this->business();
        $this->service = $this->service($this->provider['actor'], ['title' => 'نصب برق']);
    }

    private function asCustomer(): static
    {
        return $this->actingAsUser($this->customer['user'], $this->customer['actor']);
    }

    private function asProvider(): static
    {
        return $this->actingAsUser($this->provider['user'], $this->provider['actor']);
    }

    private function place(array $extra = []): string
    {
        return $this->asCustomer()->postJson('/api/v1/orders', ['service_id' => $this->service] + $extra)->assertCreated()->json('id');
    }

    private function quote(string $id, array $o = [])
    {
        return $this->asProvider()->postJson("/api/v1/orders/$id/quote", $o + ['amount' => '1500', 'currency' => 'AFN', 'price_unit' => 'project', 'days' => 3]);
    }

    private function toAccepted(): string
    {
        $id = $this->place();
        $this->quote($id)->assertOk();
        $this->asCustomer()->postJson("/api/v1/orders/$id/accept")->assertOk();

        return $id;
    }

    private function toDone(): string
    {
        $id = $this->toAccepted();
        $this->asProvider()->postJson("/api/v1/orders/$id/done")->assertOk();

        return $id;
    }

    // ---------------------------------------------------------------- create

    public function test_ordering_a_service_creates_a_requested_order_with_its_title_snapshot(): void
    {
        $res = $this->asCustomer()->postJson('/api/v1/orders', ['service_id' => $this->service, 'description' => 'دو اتاق'])->assertCreated();

        $res->assertJsonPath('status', 'requested')->assertJsonPath('origin', 'service')->assertJsonPath('title', 'نصب برق')
            ->assertJsonPath('my_side', 'customer')->assertJsonPath('customer.id', $this->customer['actor'])->assertJsonPath('provider.id', $this->provider['actor']);
        $this->assertDatabaseHas('orders', ['id' => $res->json('id'), 'service_id' => $this->service, 'description' => 'دو اتاق']);
    }

    public function test_a_title_snapshot_survives_a_renamed_service(): void
    {
        $id = $this->place();
        DB::table('services')->where('id', $this->service)->update(['title' => 'عنوان جدید']);

        $this->asCustomer()->getJson("/api/v1/orders/$id")->assertJsonPath('title', 'نصب برق');
    }

    public function test_a_direct_order_needs_a_title_and_has_no_service(): void
    {
        $this->asCustomer()->postJson('/api/v1/orders', ['provider_actor_id' => $this->provider['actor']])->assertUnprocessable()->assertJsonValidationErrors('title');
        $res = $this->asCustomer()->postJson('/api/v1/orders', ['provider_actor_id' => $this->provider['actor'], 'title' => 'کار ویژه'])->assertCreated();

        $res->assertJsonPath('origin', 'direct')->assertJsonPath('service_id', null);
    }

    public function test_an_order_needs_a_service_or_a_provider(): void
    {
        $this->asCustomer()->postJson('/api/v1/orders', ['title' => 'x y'])->assertUnprocessable();
    }

    public function test_only_an_active_service_can_be_ordered(): void
    {
        foreach (['draft', 'paused', 'archived'] as $status) {
            $service = $this->service($this->provider['actor'], ['status' => $status]);
            $this->asCustomer()->postJson('/api/v1/orders', ['service_id' => $service])->assertUnprocessable()->assertJsonPath('code', 'order.service_unavailable');
        }
        $this->asCustomer()->postJson('/api/v1/orders', ['service_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_you_cannot_order_from_yourself(): void
    {
        $this->asProvider()->postJson('/api/v1/orders', ['service_id' => $this->service])->assertUnprocessable()->assertJsonPath('code', 'order.cannot_order_from_self');
    }

    public function test_suspension_stops_new_orders_only(): void
    {
        $existing = $this->place();
        DB::table('actors')->where('id', $this->provider['actor'])->update(['status' => 'suspended']);

        $this->asCustomer()->postJson('/api/v1/orders', ['provider_actor_id' => $this->provider['actor'], 'title' => 'کار تازه'])->assertUnprocessable()->assertJsonPath('code', 'order.actor_unavailable');
        $this->quote($existing)->assertOk();
    }

    public function test_viewers_cannot_place_orders_and_the_header_is_required(): void
    {
        $page = $this->business($this->customer);
        $viewer = $this->member($page['actor'], 'viewer');
        $editor = $this->member($page['actor'], 'editor');

        $this->actingAsUser($viewer, $page['actor'])->postJson('/api/v1/orders', ['service_id' => $this->service])->assertForbidden();
        $this->actingAsUser($editor, $page['actor'])->postJson('/api/v1/orders', ['service_id' => $this->service])->assertCreated()->assertJsonPath('customer.id', $page['actor']);
        $this->actingAsUser($this->customer['user'])->withoutHeader('X-Actor-Id')->postJson('/api/v1/orders', ['service_id' => $this->service])->assertStatus(400);
        $this->actingAsUser($this->customer['user'], $this->provider['actor'])->postJson('/api/v1/orders', ['service_id' => $this->service])->assertForbidden();
    }

    public function test_creating_an_order_opens_a_conversation_and_notifies_the_provider_team(): void
    {
        $editor = $this->member($this->provider['actor'], 'editor');
        $id = $this->place();

        $conversation = DB::table('conversations')->where('order_id', $id)->first();
        $this->assertNotNull($conversation);
        $this->assertEqualsCanonicalizing([$this->customer['actor'], $this->provider['actor']], DB::table('conversation_participants')->where('conversation_id', $conversation->id)->pluck('actor_id')->all());
        $this->assertEqualsCanonicalizing([$this->provider['user'], $editor], DB::table('notifications')->where('type', 'order.requested')->pluck('user_id')->all());
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->customer['user']]);
        $this->assertDatabaseHas('order_events', ['order_id' => $id, 'event' => 'created', 'by_actor_id' => $this->customer['actor'], 'to_status' => 'requested']);
    }

    // ---------------------------------------------------------------- quote and accept

    public function test_the_provider_quotes_and_the_order_becomes_quoted(): void
    {
        $id = $this->place();

        $this->quote($id, ['amount' => '۱٬۵۰۰', 'note' => 'با مواد'])->assertOk()
            ->assertJsonPath('status', 'quoted')->assertJsonPath('pending_quote.amount', '1500.00')->assertJsonPath('pending_quote.currency', 'AFN')
            ->assertJsonPath('pending_quote.unit', 'project')->assertJsonPath('pending_quote.days', 3)->assertJsonPath('pending_quote.note', 'با مواد');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer['user'], 'type' => 'order.quoted']);
    }

    public function test_a_new_quote_replaces_the_pending_one(): void
    {
        $id = $this->place();
        $this->quote($id)->assertOk();
        $this->quote($id, ['amount' => '1200', 'currency' => 'USD'])->assertOk()->assertJsonPath('pending_quote.currency', 'USD');

        $this->assertSame(1, DB::table('quotes')->where(['order_id' => $id, 'status' => 'pending'])->count());
        $this->assertSame(1, DB::table('quotes')->where(['order_id' => $id, 'status' => 'superseded'])->count());
        $this->asCustomer()->getJson("/api/v1/orders/$id")->assertJsonCount(2, 'quotes');
    }

    public function test_quote_values_are_validated(): void
    {
        $id = $this->place();

        $this->quote($id, ['amount' => '0'])->assertUnprocessable();
        $this->quote($id, ['amount' => 'abc'])->assertUnprocessable();
        $this->quote($id, ['currency' => 'EUR'])->assertUnprocessable();
        $this->quote($id, ['price_unit' => 'week'])->assertUnprocessable();
        $this->quote($id, ['days' => 0])->assertUnprocessable();
        $this->assertSame(0, DB::table('quotes')->count());
    }

    public function test_only_the_provider_can_quote(): void
    {
        $id = $this->place();

        $this->asCustomer()->postJson("/api/v1/orders/$id/quote", ['amount' => 1, 'currency' => 'AFN', 'price_unit' => 'hour'])->assertForbidden()->assertJsonPath('code', 'order.wrong_side');
        $stranger = $this->person();
        $this->actingAsUser($stranger['user'], $stranger['actor'])->postJson("/api/v1/orders/$id/quote", ['amount' => 1, 'currency' => 'AFN', 'price_unit' => 'hour'])->assertNotFound();
    }

    public function test_accepting_locks_the_agreed_price(): void
    {
        $id = $this->place();
        $this->quote($id, ['amount' => '2500', 'currency' => 'USD', 'price_unit' => 'day'])->assertOk();

        $this->asCustomer()->postJson("/api/v1/orders/$id/accept")->assertOk()
            ->assertJsonPath('status', 'accepted')->assertJsonPath('agreed.amount', '2500.00')->assertJsonPath('agreed.currency', 'USD')->assertJsonPath('agreed.unit', 'day');
        $this->assertDatabaseHas('quotes', ['order_id' => $id, 'status' => 'accepted']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->provider['user'], 'type' => 'order.accepted']);
    }

    public function test_the_provider_cannot_accept_its_own_quote_and_a_quote_is_needed(): void
    {
        $id = $this->place();
        $this->asCustomer()->postJson("/api/v1/orders/$id/accept")->assertStatus(409)->assertJsonPath('code', 'order.invalid_state');

        $this->quote($id)->assertOk();
        $this->asProvider()->postJson("/api/v1/orders/$id/accept")->assertForbidden();
    }

    public function test_quotes_are_refused_once_the_order_is_accepted(): void
    {
        $id = $this->toAccepted();

        $this->quote($id)->assertStatus(409)->assertJsonPath('code', 'order.invalid_state');
    }

    // ---------------------------------------------------------------- reject, cancel

    public function test_the_provider_can_reject_a_request_or_a_quoted_order(): void
    {
        $requested = $this->place();
        $this->asProvider()->postJson("/api/v1/orders/$requested/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer['user'], 'type' => 'order.rejected']);

        $quoted = $this->place();
        $this->quote($quoted)->assertOk();
        $this->asProvider()->postJson("/api/v1/orders/$quoted/reject")->assertOk();
        $this->assertSame(0, DB::table('quotes')->where(['order_id' => $quoted, 'status' => 'pending'])->count());
    }

    public function test_the_customer_can_cancel_before_acceptance_but_not_after(): void
    {
        $id = $this->place();
        $this->quote($id)->assertOk();
        $this->asCustomer()->postJson("/api/v1/orders/$id/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->asCustomer()->postJson("/api/v1/orders/$id/accept")->assertStatus(409);

        $accepted = $this->toAccepted();
        $this->asCustomer()->postJson("/api/v1/orders/$accepted/cancel")->assertStatus(409);
        $this->asProvider()->postJson("/api/v1/orders/$id/cancel")->assertForbidden();
    }

    public function test_a_finished_order_accepts_no_more_steps(): void
    {
        $id = $this->place();
        $this->asProvider()->postJson("/api/v1/orders/$id/reject")->assertOk();

        foreach (['quote', 'accept', 'reject', 'cancel', 'done', 'confirm', 'dispute'] as $step) {
            $who = in_array($step, ['quote', 'reject', 'done'], true) ? 'asProvider' : 'asCustomer';
            $this->$who()->postJson("/api/v1/orders/$id/$step", ['amount' => 1, 'currency' => 'AFN', 'price_unit' => 'hour'])->assertStatus(409);
        }
    }

    // ---------------------------------------------------------------- done, confirm, dispute

    public function test_the_provider_marks_the_work_done_and_the_customer_confirms(): void
    {
        $id = $this->toAccepted();
        $this->asCustomer()->postJson("/api/v1/orders/$id/done")->assertForbidden();
        $this->asProvider()->postJson("/api/v1/orders/$id/done")->assertOk()->assertJsonPath('status', 'provider_done');
        $this->asProvider()->postJson("/api/v1/orders/$id/confirm")->assertForbidden();

        $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('agreed.amount', '1500.00');
        $this->assertNotNull(DB::table('orders')->where('id', $id)->value('completed_at'));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer['user'], 'type' => 'order.review_invite']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->provider['user'], 'type' => 'order.completed']);
    }

    public function test_confirming_updates_the_completed_count_of_the_provider(): void
    {
        foreach ([1, 2] as $i) {
            $id = $this->toDone();
            $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertOk();
        }

        $this->assertDatabaseHas('actor_stats', ['actor_id' => $this->provider['actor'], 'completed_count' => 2, 'rating_count' => 0]);
        $this->getJson("/api/v1/actors/{$this->provider['actor']}")->assertJsonPath('stats.completed_count', 2);
    }

    public function test_confirming_is_not_possible_before_the_provider_is_done(): void
    {
        $id = $this->toAccepted();

        $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertStatus(409);
    }

    public function test_the_customer_can_dispute_a_finished_job(): void
    {
        $id = $this->toDone();

        $this->asCustomer()->postJson("/api/v1/orders/$id/dispute")->assertOk()->assertJsonPath('status', 'disputed');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->provider['user'], 'type' => 'order.disputed']);
        $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertStatus(409);
    }

    public function test_a_completed_order_keeps_its_price_for_ever(): void
    {
        $id = $this->toDone();
        $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertOk();

        $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['agreed_amount' => 1]), 'locked');
    }

    // ---------------------------------------------------------------- history

    public function test_every_step_leaves_one_event_in_order(): void
    {
        $id = $this->toDone();
        $this->asCustomer()->postJson("/api/v1/orders/$id/confirm")->assertOk();

        $events = $this->asCustomer()->getJson("/api/v1/orders/$id")->json('events');

        $this->assertSame(['created', 'quoted', 'accepted', 'provider_done', 'completed'], array_column($events, 'event'));
        $this->assertSame([null, 'requested', 'quoted', 'accepted', 'provider_done'], array_column($events, 'from'));
        $this->assertSame([$this->customer['actor'], $this->provider['actor'], $this->customer['actor'], $this->provider['actor'], $this->customer['actor']], array_column($events, 'by_actor_id'));
    }

    // ---------------------------------------------------------------- team of a page as provider

    public function test_editors_of_a_provider_page_answer_orders_but_viewers_only_look(): void
    {
        $editor = $this->member($this->provider['actor'], 'editor');
        $viewer = $this->member($this->provider['actor'], 'viewer');
        $id = $this->place();
        $body = ['amount' => 100, 'currency' => 'AFN', 'price_unit' => 'hour'];

        $this->actingAsUser($viewer, $this->provider['actor'])->getJson("/api/v1/orders/$id")->assertOk();
        $this->actingAsUser($viewer, $this->provider['actor'])->postJson("/api/v1/orders/$id/quote", $body)->assertForbidden();
        $this->actingAsUser($editor, $this->provider['actor'])->postJson("/api/v1/orders/$id/quote", $body)->assertOk();
    }

    public function test_someone_who_is_not_in_the_team_cannot_borrow_the_actor(): void
    {
        $id = $this->place();
        $outsider = $this->person();

        $this->actingAsUser($outsider['user'], $this->provider['actor'])->getJson("/api/v1/orders/$id")->assertForbidden()->assertJsonPath('code', 'actor.forbidden');
        $this->actingAsUser($outsider['user'], $outsider['actor'])->getJson("/api/v1/orders/$id")->assertNotFound();
    }

    // ---------------------------------------------------------------- list

    public function test_each_side_sees_only_its_own_orders_with_a_role_filter(): void
    {
        $mine = $this->place();
        $this->asProvider()->postJson('/api/v1/orders', ['provider_actor_id' => $this->customer['actor'], 'title' => 'برعکس'])->assertCreated();
        $other = $this->order();   // between two strangers

        $all = array_column($this->asCustomer()->getJson('/api/v1/orders')->assertOk()->json('data'), 'id');
        $this->assertCount(2, $all);
        $this->assertNotContains($other, $all);
        $this->assertSame([$mine], array_column($this->asCustomer()->getJson('/api/v1/orders?role=customer')->json('data'), 'id'));
        $this->assertCount(1, $this->asCustomer()->getJson('/api/v1/orders?role=provider')->json('data'));
        $this->assertSame('provider', $this->asCustomer()->getJson('/api/v1/orders?role=provider')->json('data.0.my_side'));
    }

    public function test_the_list_can_filter_by_status(): void
    {
        $requested = $this->place();
        $accepted = $this->toAccepted();

        $this->assertSame([$accepted], array_column($this->asCustomer()->getJson('/api/v1/orders?status=accepted')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$requested, $accepted], array_column($this->asCustomer()->getJson('/api/v1/orders?status=requested,accepted')->json('data'), 'id'));
    }

    public function test_the_list_is_paged_with_a_cursor_newest_first(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->place();
            $this->travel(1)->minute();
        }

        $first = $this->asCustomer()->getJson('/api/v1/orders?limit=2')->assertOk();
        $second = $this->asCustomer()->getJson('/api/v1/orders?limit=2&cursor='.$first->json('next_cursor'))->assertOk();
        $third = $this->asCustomer()->getJson('/api/v1/orders?limit=2&cursor='.$second->json('next_cursor'))->assertOk();

        $this->assertSame(array_reverse($ids), array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id')));
        $this->assertNull($third->json('next_cursor'));
        $this->asCustomer()->getJson('/api/v1/orders?cursor=bad')->assertUnprocessable()->assertJsonPath('code', 'orders.bad_cursor');
    }

    public function test_orders_need_login(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
    }
}
