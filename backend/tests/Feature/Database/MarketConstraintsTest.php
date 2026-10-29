<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class MarketConstraintsTest extends DatabaseTestCase
{
    // ---------------------------------------------------------------- needs and offers

    public function test_job_needs_cannot_receive_offers_but_service_needs_can(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $service = $this->need($customer, ['kind' => 'service']);
        $job = $this->need($customer, ['kind' => 'job']);

        $this->offer($service, $provider);
        $this->assertRejected(fn () => $this->offer($job, $provider), 'offers_need_service_only');
    }

    public function test_need_kind_and_status_are_restricted(): void
    {
        $actor = $this->person()['actor'];

        $this->assertRejected(fn () => $this->need($actor, ['kind' => 'gig']), 'needs_kind_valid');
        $this->assertRejected(fn () => $this->need($actor, ['status' => 'done']), 'needs_status_valid');
        $this->assertRejected(fn () => $this->need($actor, ['title' => '']), 'needs_title_not_blank');
    }

    public function test_one_offer_per_provider_per_need(): void
    {
        $need = $this->need($this->person()['actor']);
        $provider = $this->business()['actor'];
        $this->offer($need, $provider);

        $this->assertRejected(fn () => $this->offer($need, $provider), 'offers_one_per_provider');
    }

    public function test_an_actor_cannot_offer_on_its_own_need(): void
    {
        $actor = $this->person()['actor'];

        $this->assertRejected(fn () => $this->offer($this->need($actor), $actor), 'own need');
    }

    public function test_offers_need_an_open_need(): void
    {
        $need = $this->need($this->person()['actor'], ['status' => 'closed']);

        $this->assertRejected(fn () => $this->offer($need, $this->business()['actor']), 'open needs');
    }

    public function test_only_one_offer_can_be_accepted_per_need(): void
    {
        $need = $this->need($this->person()['actor']);
        $first = $this->offer($need, $this->business()['actor']);
        $second = $this->offer($need, $this->business()['actor']);

        DB::table('offers')->where('id', $first)->update(['status' => 'accepted']);
        $this->assertRejected(fn () => DB::table('offers')->where('id', $second)->update(['status' => 'accepted']), 'one_accepted_offer_per_need');
    }

    public function test_offer_amount_currency_unit_and_status_are_validated(): void
    {
        $need = $this->need($this->person()['actor']);

        $this->assertRejected(fn () => $this->offer($need, $this->business()['actor'], ['amount' => 0]), 'offers_amount_positive');
        $this->assertRejected(fn () => $this->offer($need, $this->business()['actor'], ['currency' => 'PKR']), 'offers_currency_valid');
        $this->assertRejected(fn () => $this->offer($need, $this->business()['actor'], ['price_unit' => 'week']), 'offers_price_unit_valid');
        $this->assertRejected(fn () => $this->offer($need, $this->business()['actor'], ['status' => 'won']), 'offers_status_valid');
    }

    // ---------------------------------------------------------------- order shape

    public function test_a_service_order_needs_the_providers_own_service(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $service = $this->service($provider);

        $this->order($customer, $provider, ['origin' => 'service', 'service_id' => $service]);
        $this->assertRejected(fn () => $this->order($customer, $this->business()['actor'], ['origin' => 'service', 'service_id' => $service]), 'orders_service_of_provider');
        $this->assertRejected(fn () => $this->order($customer, $provider, ['origin' => 'service']), 'orders_origin_links');
    }

    public function test_direct_and_invited_orders_have_no_links(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $service = $this->service($provider);

        $this->assertRejected(fn () => $this->order($customer, $provider, ['origin' => 'direct', 'service_id' => $service]), 'orders_origin_links');
        $this->assertRejected(fn () => $this->order($customer, $provider, ['origin' => 'invited', 'status' => 'invited', 'service_id' => $service]), 'orders_origin_links');
    }

    public function test_an_offer_order_follows_an_offer_of_that_provider_on_that_customers_need(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $need = $this->need($customer);
        $offer = $this->offer($need, $provider);
        $row = ['origin' => 'offer', 'offer_id' => $offer, 'need_id' => $need, 'status' => 'accepted', 'agreed_amount' => 500, 'currency' => 'AFN'];

        $this->order($customer, $provider, $row);
        // one order per offer
        $this->assertRejected(fn () => $this->order($customer, $provider, $row), 'one_order_per_offer');
    }

    public function test_an_offer_order_cannot_mix_parties_needs_or_offers(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $need = $this->need($customer);
        $offer = $this->offer($need, $provider);
        $otherNeed = $this->need($customer);
        $row = ['origin' => 'offer', 'offer_id' => $offer, 'need_id' => $need];

        $this->assertRejected(fn () => $this->order($customer, $this->business()['actor'], $row), 'orders_offer_of_provider');
        $this->assertRejected(fn () => $this->order($this->person()['actor'], $provider, $row), 'orders_need_of_customer');
        $this->assertRejected(fn () => $this->order($customer, $provider, ['need_id' => $otherNeed] + $row), 'orders_offer_matches_need');
        $this->assertRejected(fn () => $this->order($customer, $provider, ['need_id' => null] + $row), 'orders_origin_links');
    }

    public function test_a_job_need_never_becomes_an_order(): void
    {
        $customer = $this->person()['actor'];
        $job = $this->need($customer, ['kind' => 'job']);

        $this->assertRejected(fn () => $this->order($customer, $this->business()['actor'], ['origin' => 'offer', 'need_id' => $job, 'offer_id' => (string) Str::uuid()]), 'orders_');
    }

    public function test_parties_must_differ(): void
    {
        $actor = $this->person()['actor'];

        $this->assertRejected(fn () => $this->order($actor, $actor), 'orders_parties_differ');
    }

    public function test_order_status_origin_currency_and_amount_are_validated(): void
    {
        $a = $this->person()['actor'];
        $b = $this->business()['actor'];

        $this->assertRejected(fn () => $this->order($a, $b, ['origin' => 'barter']), 'constraint "orders_origin_');
        $this->assertRejected(fn () => $this->order($a, $b, ['status' => 'maybe']), 'orders_status_valid');
        $this->assertRejected(fn () => $this->order($a, $b, ['agreed_amount' => 10, 'currency' => 'EUR']), 'orders_currency_valid');
        $this->assertRejected(fn () => $this->order($a, $b, ['agreed_amount' => 10]), 'orders_amount_has_currency');
        $this->assertRejected(fn () => $this->order($a, $b, ['agreed_amount' => -5, 'currency' => 'AFN']), 'orders_amount_positive');
        $this->assertRejected(fn () => $this->order($a, $b, ['title_snapshot' => ' ']), 'orders_title_not_blank');
    }

    public function test_first_status_depends_on_the_origin(): void
    {
        $a = $this->person()['actor'];
        $b = $this->business()['actor'];

        $this->assertRejected(fn () => $this->order($a, $b, ['status' => 'quoted']), 'invalid first status');
        $this->assertRejected(fn () => $this->order($a, $b, ['status' => 'invited']), 'invalid first status');
        $this->assertRejected(fn () => $this->order($a, $b, ['origin' => 'invited', 'status' => 'requested']), 'invalid first status');
    }

    public function test_money_keeps_its_own_currency_and_unit(): void
    {
        $id = $this->order(null, null);
        $this->advance($id, 'accepted');

        $this->assertDatabaseHas('orders', ['id' => $id, 'currency' => 'AFN', 'price_unit' => 'project']);
        $this->assertEquals('1000.00', DB::table('orders')->where('id', $id)->value('agreed_amount'));
    }

    // ---------------------------------------------------------------- status machine

    /** @return array<string, array{string, string}> */
    public static function legalTransitions(): array
    {
        return [
            'requested>quoted' => ['requested', 'quoted'], 'requested>rejected' => ['requested', 'rejected'],
            'requested>cancelled' => ['requested', 'cancelled'], 'requested>expired' => ['requested', 'expired'],
            'quoted>accepted' => ['quoted', 'accepted'], 'quoted>rejected' => ['quoted', 'rejected'],
            'quoted>cancelled' => ['quoted', 'cancelled'], 'quoted>expired' => ['quoted', 'expired'],
            'accepted>provider_done' => ['accepted', 'provider_done'],
            'provider_done>completed' => ['provider_done', 'completed'], 'provider_done>disputed' => ['provider_done', 'disputed'],
        ];
    }

    private function orderIn(string $status): string
    {
        $id = $this->order();
        match ($status) {
            'requested' => null,
            'quoted', 'accepted', 'provider_done', 'completed' => $this->advance($id, $status),
            'rejected', 'cancelled', 'expired' => DB::table('orders')->where('id', $id)->update(['status' => $status]),
            'disputed' => (function () use ($id) {
                $this->advance($id, 'provider_done');
                DB::table('orders')->where('id', $id)->update(['status' => 'disputed']);
            })(),
        };

        return $id;
    }

    #[DataProvider('legalTransitions')]
    public function test_legal_status_changes_are_accepted(string $from, string $to): void
    {
        $id = $this->orderIn($from);
        $set = ['status' => $to];
        if ($to === 'accepted') {
            $set += ['agreed_amount' => 10, 'currency' => 'AFN'];
        }
        if ($to === 'completed') {
            $set['completed_at'] = now();
        }

        DB::table('orders')->where('id', $id)->update($set);

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => $to]);
    }

    public function test_every_other_status_change_is_refused(): void
    {
        $statuses = ['requested', 'quoted', 'accepted', 'provider_done', 'completed', 'rejected', 'cancelled', 'expired', 'disputed'];
        $legal = array_map(fn ($t) => $t[0].'>'.$t[1], self::legalTransitions());

        foreach ($statuses as $from) {
            foreach ($statuses as $to) {
                if ($from === $to || in_array("$from>$to", $legal, true)) {
                    continue;
                }
                $id = $this->orderIn($from);
                $this->assertRejected(
                    fn () => DB::table('orders')->where('id', $id)->update(['status' => $to, 'agreed_amount' => 10, 'currency' => 'AFN', 'completed_at' => $to === 'completed' ? now() : null]),
                    'illegal order status change'
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_invited_orders_can_only_be_completed_by_the_customers_confirmation(): void
    {
        $provider = $this->business()['actor'];
        $id = $this->order(null, $provider, ['origin' => 'invited', 'status' => 'invited', 'title_snapshot' => 'کار گذشته']);

        $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['status' => 'accepted', 'agreed_amount' => 1, 'currency' => 'AFN']), 'illegal order status change');
        DB::table('orders')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'completed', 'origin' => 'invited']);
    }

    public function test_completed_at_is_set_exactly_when_the_order_is_completed(): void
    {
        $id = $this->orderIn('provider_done');

        $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['status' => 'completed']), 'orders_completed_at_matches');
        $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['completed_at' => now()]), 'orders_completed_at_matches');
    }

    public function test_accepting_needs_an_agreed_price(): void
    {
        $id = $this->orderIn('quoted');

        $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['status' => 'accepted']), 'orders_accepted_has_price');
    }

    public function test_price_title_and_parties_are_locked_from_acceptance_on(): void
    {
        foreach (['accepted', 'provider_done', 'completed'] as $status) {
            $id = $this->orderIn($status);

            $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['agreed_amount' => 1]), 'locked');
            $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['currency' => 'USD']), 'locked');
            $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['price_unit' => 'hour']), 'locked');
            $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['title_snapshot' => 'other']), 'locked');
            $this->assertRejected(fn () => DB::table('orders')->where('id', $id)->update(['provider_actor_id' => $this->person()['actor']]), 'locked');
        }
    }

    public function test_price_can_still_change_before_acceptance_and_description_always_can(): void
    {
        $id = $this->orderIn('quoted');
        DB::table('orders')->where('id', $id)->update(['agreed_amount' => 5, 'currency' => 'AFN']);

        $this->advance($id, 'accepted');
        DB::table('orders')->where('id', $id)->update(['description' => 'details added later']);
        $this->assertDatabaseHas('orders', ['id' => $id, 'description' => 'details added later']);
    }

    // ---------------------------------------------------------------- who can take part

    public function test_suspended_actors_get_no_new_orders_but_keep_their_old_ones(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $existing = $this->order($customer, $provider);

        DB::table('actors')->where('id', $provider)->update(['status' => 'suspended']);

        $this->assertRejected(fn () => $this->order($customer, $provider), 'suspended');
        $this->assertRejected(fn () => $this->order($provider, $customer), 'suspended');
        DB::table('orders')->where('id', $existing)->update(['status' => 'quoted']); // existing work goes on
        $this->assertDatabaseHas('orders', ['id' => $existing, 'status' => 'quoted']);
    }

    public function test_a_page_with_open_orders_cannot_be_deleted(): void
    {
        $page = $this->business();
        $customer = $this->person()['actor'];
        $id = $this->order($customer, $page['actor']);
        $delete = ['deletion_requested_by' => $page['user'], 'deletion_requested_at' => now(), 'deletion_confirmed_by' => $page['user']];

        foreach (['requested', 'quoted', 'accepted', 'provider_done'] as $status) {
            if ($status !== 'requested') {
                DB::table('orders')->where('id', $id)->update(['status' => $status] + ($status === 'accepted' ? ['agreed_amount' => 1, 'currency' => 'AFN'] : []));
            }
            $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->update($delete + ['status' => 'deleted']), 'open orders');
            $this->assertRejected(fn () => DB::table('actors')->where('id', $page['actor'])->delete(), 'open orders');
        }

        DB::table('orders')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now()]);
        DB::table('actors')->where('id', $page['actor'])->update($delete + ['status' => 'deleted']);
        $this->assertDatabaseHas('actors', ['id' => $page['actor'], 'status' => 'deleted']);
    }

    public function test_a_page_with_open_orders_as_customer_cannot_be_deleted_either(): void
    {
        $page = $this->business();
        $this->order($page['actor'], $this->person()['actor']);

        $this->assertRejected(
            fn () => DB::table('actors')->where('id', $page['actor'])->update(['deletion_requested_by' => $page['user'], 'deletion_requested_at' => now(), 'deletion_confirmed_by' => $page['user'], 'status' => 'deleted']),
            'open orders'
        );
    }

    // ---------------------------------------------------------------- invited past jobs

    private function invited(string $provider, array $o = []): string
    {
        return $this->order(null, $provider, $o + ['origin' => 'invited', 'status' => 'invited', 'title_snapshot' => 'کار گذشته']);
    }

    public function test_a_provider_can_invite_five_past_jobs_per_month_not_six(): void
    {
        $provider = $this->business()['actor'];
        foreach (range(1, 5) as $i) {
            $this->invited($provider, ['created_at' => '2026-10-10 08:00:00+00']);
        }

        $this->assertRejected(fn () => $this->invited($provider, ['created_at' => '2026-10-20 08:00:00+00']), 'limit reached');
    }

    public function test_the_cap_is_per_provider_and_resets_next_month(): void
    {
        $provider = $this->business()['actor'];
        foreach (range(1, 5) as $i) {
            $this->invited($provider, ['created_at' => '2026-10-10 08:00:00+00']);
        }

        $this->invited($this->business()['actor'], ['created_at' => '2026-10-10 08:00:00+00']);
        $this->invited($provider, ['created_at' => '2026-09-30 08:00:00+00']);
        $this->invited($provider, ['created_at' => '2026-11-15 08:00:00+00']);
        $this->addToAssertionCount(1);
    }

    public function test_the_month_boundary_follows_kabul_time(): void
    {
        $provider = $this->business()['actor'];
        // 2026-10-31 20:00 UTC is already 2026-11-01 00:30 in Kabul (UTC+4:30).
        foreach (range(1, 5) as $i) {
            $this->invited($provider, ['created_at' => '2026-10-31 20:00:00+00']);
        }
        $this->invited($provider, ['created_at' => '2026-10-31 10:00:00+00']); // still October in Kabul

        $this->assertRejected(fn () => $this->invited($provider, ['created_at' => '2026-11-10 10:00:00+00']), 'limit reached');
    }

    public function test_other_origins_do_not_count_towards_the_cap(): void
    {
        $provider = $this->business()['actor'];
        foreach (range(1, 6) as $i) {
            $this->order(null, $provider);
        }
        foreach (range(1, 5) as $i) {
            $this->invited($provider);
        }
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------- quotes

    private function quote(string $orderId, array $o = []): string
    {
        return $this->insert('quotes', $o + ['order_id' => $orderId, 'amount' => 700, 'currency' => 'AFN', 'price_unit' => 'project', 'days' => 3]);
    }

    public function test_an_order_has_only_one_pending_quote(): void
    {
        $order = $this->order();
        $first = $this->quote($order);

        $this->assertRejected(fn () => $this->quote($order), 'one_pending_quote');

        DB::table('quotes')->where('id', $first)->update(['status' => 'superseded']);
        $this->quote($order);
        $this->assertSame(2, DB::table('quotes')->where('order_id', $order)->count());
    }

    public function test_quote_values_are_validated(): void
    {
        $order = $this->order();

        $this->assertRejected(fn () => $this->quote($order, ['amount' => 0]), 'quotes_amount_positive');
        $this->assertRejected(fn () => $this->quote($order, ['currency' => 'EUR']), 'quotes_currency_valid');
        $this->assertRejected(fn () => $this->quote($order, ['price_unit' => 'week']), 'quotes_price_unit_valid');
        $this->assertRejected(fn () => $this->quote($order, ['days' => 0]), 'quotes_days_positive');
        $this->assertRejected(fn () => $this->quote($order, ['status' => 'maybe']), 'quotes_status_valid');
    }

    public function test_new_quotes_only_while_the_order_is_requested_or_quoted(): void
    {
        foreach (['accepted', 'provider_done'] as $status) {
            $order = $this->orderIn($status);

            $this->assertRejected(fn () => $this->quote($order), 'requested or quoted');
        }
        $this->assertRejected(fn () => $this->quote($this->orderIn('completed')), 'completed order');
        $this->quote($this->orderIn('quoted'));
        $this->addToAssertionCount(1);
    }

    public function test_quotes_of_a_completed_order_cannot_change_or_disappear(): void
    {
        $order = $this->order();
        $quote = $this->quote($order);
        $this->advance($order, 'completed');

        $this->assertRejected(fn () => DB::table('quotes')->where('id', $quote)->update(['amount' => 1]), 'completed order cannot change');
        $this->assertRejected(fn () => DB::table('quotes')->where('id', $quote)->delete(), 'completed order cannot change');
        $this->assertRejected(fn () => $this->quote($order, ['status' => 'superseded']), 'completed order cannot change');
        $this->assertEquals(700, DB::table('quotes')->where('id', $quote)->value('amount'));
    }

    // ---------------------------------------------------------------- order events

    public function test_order_events_are_append_only(): void
    {
        $order = $this->order();
        $event = $this->insert('order_events', ['order_id' => $order, 'event' => 'created', 'to_status' => 'requested']);

        $this->assertRejected(fn () => DB::table('order_events')->where('id', $event)->update(['event' => 'tampered']), 'append-only');
        $this->assertRejected(fn () => DB::table('order_events')->where('id', $event)->delete(), 'append-only');
        $this->assertRejected(fn () => $this->insert('order_events', ['order_id' => $order, 'event' => 'Bad Name']), 'order_events_event_format');
        $this->assertRejected(fn () => $this->insert('order_events', ['order_id' => $order, 'event' => 'x', 'to_status' => 'flying']), 'order_events_status_valid');
    }

    // ---------------------------------------------------------------- reviews

    private function review(string $order, string $customer, string $provider, array $o = []): string
    {
        return $this->insert('reviews', $o + ['order_id' => $order, 'customer_actor_id' => $customer, 'provider_actor_id' => $provider, 'stars' => 5]);
    }

    private function completedOrder(): array
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $order = $this->order($customer, $provider);
        $this->advance($order, 'completed');

        return [$order, $customer, $provider];
    }

    public function test_a_review_is_only_allowed_after_completion(): void
    {
        foreach (['requested', 'quoted', 'accepted', 'provider_done'] as $status) {
            $customer = $this->person()['actor'];
            $provider = $this->business()['actor'];
            $order = $this->order($customer, $provider);
            if ($status !== 'requested') {
                $this->advance($order, $status);
            }

            $this->assertRejected(fn () => $this->review($order, $customer, $provider), 'only allowed for a completed order');
        }

        [$order, $customer, $provider] = $this->completedOrder();
        $this->review($order, $customer, $provider);
        $this->assertSame(1, DB::table('reviews')->count());
    }

    public function test_a_review_must_name_the_real_customer_and_provider_of_the_order(): void
    {
        [$order, $customer, $provider] = $this->completedOrder();
        $stranger = $this->person()['actor'];

        $this->assertRejected(fn () => $this->review($order, $stranger, $provider), 'reviews_order_parties_fk');
        $this->assertRejected(fn () => $this->review($order, $customer, $stranger), 'reviews_order_parties_fk');
        $this->assertRejected(fn () => $this->review($order, $provider, $customer), 'reviews_order_parties_fk');
    }

    public function test_an_order_has_at_most_one_review(): void
    {
        [$order, $customer, $provider] = $this->completedOrder();
        $this->review($order, $customer, $provider);

        $this->assertRejected(fn () => $this->review($order, $customer, $provider, ['stars' => 1]), 'reviews_order_unique');
    }

    public function test_an_invited_past_job_can_be_reviewed_after_it_is_completed(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->business()['actor'];
        $order = $this->insert('orders', ['origin' => 'invited', 'status' => 'invited', 'customer_actor_id' => $customer, 'provider_actor_id' => $provider, 'title_snapshot' => 'کار گذشته']);

        $this->assertRejected(fn () => $this->review($order, $customer, $provider), 'completed order');
        DB::table('orders')->where('id', $order)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->review($order, $customer, $provider);
        $this->addToAssertionCount(1);
    }

    public function test_stars_are_one_to_five_and_a_reply_comes_with_its_time(): void
    {
        [$order, $customer, $provider] = $this->completedOrder();

        $this->assertRejected(fn () => $this->review($order, $customer, $provider, ['stars' => 0]), 'reviews_stars_range');
        $this->assertRejected(fn () => $this->review($order, $customer, $provider, ['stars' => 6]), 'reviews_stars_range');
        $this->assertRejected(fn () => $this->review($order, $customer, $provider, ['reply_body' => 'ممنون']), 'reviews_reply_pair');
        $this->review($order, $customer, $provider, ['reply_body' => 'ممنون', 'replied_at' => now(), 'tags' => '{"به‌موقع","تمیز"}']);
        $this->addToAssertionCount(1);
    }

    public function test_a_completed_order_stays_untouched_after_its_review(): void
    {
        [$order, $customer, $provider] = $this->completedOrder();
        $this->review($order, $customer, $provider);

        $this->assertRejected(fn () => DB::table('orders')->where('id', $order)->update(['agreed_amount' => 1]), 'locked');
        $this->assertRejected(fn () => DB::table('orders')->where('id', $order)->update(['status' => 'disputed']), 'illegal order status change');
    }

    // ---------------------------------------------------------------- stats

    public function test_actor_stats_are_consistent(): void
    {
        $actor = $this->business()['actor'];

        $this->assertRejected(fn () => DB::table('actor_stats')->insert(['actor_id' => $actor, 'completed_count' => -1]), 'actor_stats_counts_valid');
        $this->assertRejected(fn () => DB::table('actor_stats')->insert(['actor_id' => $actor, 'rating_count' => 2, 'rating_avg' => 6]), 'actor_stats_avg_range');
        $this->assertRejected(fn () => DB::table('actor_stats')->insert(['actor_id' => $actor, 'rating_count' => 2]), 'actor_stats_avg_matches_count');
        $this->assertRejected(fn () => DB::table('actor_stats')->insert(['actor_id' => $actor, 'rating_avg' => 4.5]), 'actor_stats_avg_matches_count');

        DB::table('actor_stats')->insert(['actor_id' => $actor, 'completed_count' => 3, 'rating_count' => 2, 'rating_avg' => 4.5]);
        $this->assertRejected(fn () => DB::table('actor_stats')->insert(['actor_id' => $actor]), 'actor_stats_pkey');
    }
}
