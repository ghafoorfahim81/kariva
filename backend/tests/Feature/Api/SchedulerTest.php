<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchedulerTest extends ApiTestCase
{
    private function pair(): array
    {
        return [$this->person()['actor'], $this->business()['actor']];
    }

    private function eventsOf(string $order): array
    {
        return DB::table('order_events')->where('order_id', $order)->orderBy('at')->pluck('event')->all();
    }

    public function test_a_request_nobody_answers_expires_after_72_hours(): void
    {
        [$c, $p] = $this->pair();
        $old = $this->order($c, $p, ['created_at' => now()->subHours(73)]);
        $fresh = $this->order($c, $p, ['created_at' => now()->subHours(71)]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $old, 'status' => 'expired']);
        $this->assertDatabaseHas('orders', ['id' => $fresh, 'status' => 'requested']);
        $this->assertSame(['expired'], $this->eventsOf($old));
        $this->assertDatabaseHas('order_events', ['order_id' => $old, 'event' => 'expired', 'by_actor_id' => null, 'to_status' => 'expired']);
        $this->assertSame(2, DB::table('notifications')->where('type', 'order.expired')->count());   // both sides are told
    }

    public function test_a_quote_nobody_accepts_expires_after_7_days_counted_from_the_quote(): void
    {
        [$c, $p] = $this->pair();
        $stale = $this->order($c, $p, ['created_at' => now()->subDays(30)]);
        $this->advance($stale, 'quoted');
        DB::table('quotes')->insert(['id' => (string) Str::uuid(), 'order_id' => $stale, 'amount' => 10, 'currency' => 'AFN', 'price_unit' => 'hour', 'created_at' => now()->subDays(8)]);
        $recent = $this->order($c, $p, ['created_at' => now()->subDays(30)]);
        $this->advance($recent, 'quoted');
        DB::table('quotes')->insert(['id' => (string) Str::uuid(), 'order_id' => $recent, 'amount' => 10, 'currency' => 'AFN', 'price_unit' => 'hour', 'created_at' => now()->subDays(6)]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $stale, 'status' => 'expired']);
        $this->assertDatabaseHas('orders', ['id' => $recent, 'status' => 'quoted']);
        $this->assertSame(0, DB::table('quotes')->where('order_id', $stale)->where('status', 'pending')->count());
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        [$c, $p] = $this->pair();
        $this->order($c, $p, ['created_at' => now()->subHours(80)]);

        $this->artisan('orders:expire')->assertSuccessful();
        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(1, DB::table('order_events')->where('event', 'expired')->count());
        $this->assertSame(2, DB::table('notifications')->where('type', 'order.expired')->count());
    }

    public function test_accepted_and_finished_orders_never_expire(): void
    {
        [$c, $p] = $this->pair();
        $accepted = $this->order($c, $p, ['created_at' => now()->subDays(60)]);
        $this->advance($accepted, 'accepted');

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $accepted, 'status' => 'accepted']);
    }

    public function test_the_customer_who_stays_silent_after_provider_done_gets_the_order_completed_after_7_days(): void
    {
        [$c, $p] = $this->pair();
        $late = $this->order($c, $p);
        $this->advance($late, 'provider_done');
        DB::table('order_events')->insert(['id' => (string) Str::uuid(), 'order_id' => $late, 'event' => 'provider_done', 'at' => now()->subDays(8)]);
        $early = $this->order($c, $p);
        $this->advance($early, 'provider_done');
        DB::table('order_events')->insert(['id' => (string) Str::uuid(), 'order_id' => $early, 'event' => 'provider_done', 'at' => now()->subDays(6)]);

        $this->artisan('orders:auto-complete')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $late, 'status' => 'completed']);
        $this->assertNotNull(DB::table('orders')->where('id', $late)->value('completed_at'));
        $this->assertDatabaseHas('orders', ['id' => $early, 'status' => 'provider_done']);
        $this->assertDatabaseHas('order_events', ['order_id' => $late, 'event' => 'auto_completed', 'by_actor_id' => null]);
        $this->assertDatabaseHas('actor_stats', ['actor_id' => $p, 'completed_count' => 1]);
    }

    public function test_a_disputed_order_is_not_auto_completed(): void
    {
        [$c, $p] = $this->pair();
        $id = $this->order($c, $p);
        $this->advance($id, 'provider_done');
        DB::table('order_events')->insert(['id' => (string) Str::uuid(), 'order_id' => $id, 'event' => 'provider_done', 'at' => now()->subDays(20)]);
        DB::table('orders')->where('id', $id)->update(['status' => 'disputed']);

        $this->artisan('orders:auto-complete')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'disputed']);
    }

    public function test_the_waiting_times_can_be_configured(): void
    {
        config(['kariva.orders.expire_requested_hours' => 24]);
        [$c, $p] = $this->pair();
        $id = $this->order($c, $p, ['created_at' => now()->subHours(25)]);

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'expired']);
    }

    public function test_needs_expire_with_their_pending_offers(): void
    {
        $owner = $this->person();
        $pro = $this->business();
        $need = $this->need($owner['actor']);
        $offer = $this->offer($need, $pro['actor']);
        DB::table('needs')->where('id', $need)->update(['expires_at' => now()->subHour()]);
        $alive = $this->need($owner['actor']);

        $this->artisan('needs:expire')->assertSuccessful();

        $this->assertDatabaseHas('needs', ['id' => $need, 'status' => 'expired']);
        $this->assertDatabaseHas('needs', ['id' => $alive, 'status' => 'open']);
        $this->assertDatabaseHas('offers', ['id' => $offer, 'status' => 'rejected']);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner['user'], 'type' => 'need.expired']);
        $this->assertDatabaseHas('notifications', ['user_id' => $pro['user'], 'type' => 'offer.rejected']);
    }

    public function test_the_commands_are_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('orders:expire')->expectsOutputToContain('orders:auto-complete')->expectsOutputToContain('needs:expire')->assertSuccessful();
    }
}
