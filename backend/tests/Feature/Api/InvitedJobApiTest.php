<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class InvitedJobApiTest extends ApiTestCase
{
    private array $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->business();
    }

    private function as(array $who): static
    {
        return $this->actingAsUser($who['user'], $who['actor']);
    }

    private function invite(string $phone = '0771111111', array $o = [])
    {
        return $this->as($this->provider)->postJson('/api/v1/invited-jobs', $o + ['phone' => $phone, 'title' => 'سیم‌کشی خانه', 'description' => 'پارسال انجام شد']);
    }

    private function confirm(string $order, array $by, string $code)
    {
        return $this->as($by)->postJson("/api/v1/orders/$order/confirm-invited", ['code' => $code]);
    }

    public function test_recording_a_past_job_prepares_the_customer_and_sends_a_code(): void
    {
        $res = $this->invite()->assertCreated()->assertJsonPath('status', 'invited')->assertJsonPath('origin', 'invited')
            ->assertJsonPath('title', 'سیم‌کشی خانه')->assertJsonPath('code_sent', true)->assertJsonPath('agreed', null);

        $user = DB::table('users')->where('phone_e164', '+93771111111')->first();
        $this->assertSame('invited', $user->status);
        $customer = DB::table('actors')->where('personal_user_id', $user->id)->first();
        $this->assertSame($customer->id, $res->json('customer.id'));
        $this->assertSame([['phone' => '+93771111111', 'code' => $this->otp->lastCode()]], $this->otp->sent);
        $this->assertDatabaseHas('otp_codes', ['phone_e164' => '+93771111111', 'purpose' => 'invited_job']);
        $this->assertDatabaseHas('order_events', ['order_id' => $res->json('id'), 'event' => 'invited']);
    }

    public function test_an_existing_user_is_reused(): void
    {
        $customer = $this->person(['phone_e164' => '+93771111111']);

        $res = $this->invite()->assertCreated();

        $this->assertSame($customer['actor'], $res->json('customer.id'));
        $this->assertSame(2, DB::table('users')->count());   // the page owner and the customer
    }

    public function test_you_cannot_invite_yourself_or_your_own_team(): void
    {
        $ownerPhone = DB::table('users')->where('id', $this->provider['user'])->value('phone_e164');

        $this->invite($ownerPhone)->assertUnprocessable()->assertJsonPath('code', 'order.cannot_order_from_self');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_phone_and_title_are_validated(): void
    {
        $this->invite('abc')->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->invite('0771111111', ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_phone_can_be_typed_with_persian_digits(): void
    {
        $this->invite('۰۷۷۱۱۱۱۱۱۱')->assertCreated();

        $this->assertDatabaseHas('users', ['phone_e164' => '+93771111111']);
    }

    public function test_only_editors_and_above_can_record_a_past_job(): void
    {
        $viewer = $this->member($this->provider['actor'], 'viewer');
        $this->postJson('/api/v1/invited-jobs', [])->assertUnauthorized();

        $this->actingAsUser($viewer, $this->provider['actor'])->postJson('/api/v1/invited-jobs', ['phone' => '0771111111', 'title' => 'کار قدیمی'])->assertForbidden();
    }

    public function test_five_per_month_and_the_sixth_is_refused_without_leaving_a_new_user(): void
    {
        foreach (range(1, 5) as $i) {
            $this->invite('07711111'.str_pad((string) $i, 2, '0', STR_PAD_LEFT))->assertCreated();
        }
        $users = DB::table('users')->count();

        $this->invite('0772222222')->assertUnprocessable()->assertJsonPath('code', 'invited.limit_reached')->assertJsonPath('max_per_month', 5);
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame(5, DB::table('orders')->where('origin', 'invited')->count());
    }

    // ---------------------------------------------------------------- confirming

    public function test_the_right_code_completes_the_job_and_counts_it(): void
    {
        $order = $this->invite()->json('id');

        $this->confirm($order, $this->provider, $this->otp->lastCode())->assertOk()->assertJsonPath('status', 'completed');

        $this->assertNotNull(DB::table('orders')->where('id', $order)->value('completed_at'));
        $this->assertDatabaseHas('actor_stats', ['actor_id' => $this->provider['actor'], 'completed_count' => 1]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order, 'event' => 'confirmed_by_code', 'from_status' => 'invited', 'to_status' => 'completed']);
        $this->assertDatabaseHas('notifications', ['type' => 'order.review_invite']);
    }

    public function test_the_customer_can_enter_the_code_too_and_then_review(): void
    {
        $order = $this->invite()->json('id');
        $user = DB::table('users')->where('phone_e164', '+93771111111')->first();
        $actor = DB::table('actors')->where('personal_user_id', $user->id)->value('id');
        $customer = ['user' => $user->id, 'actor' => $actor];

        $this->confirm($order, $customer, $this->otp->lastCode())->assertOk();
        $this->as($customer)->postJson("/api/v1/orders/$order/review", ['stars' => 5, 'body' => 'کارش خوب بود'])->assertCreated();
        $this->assertDatabaseHas('actor_stats', ['actor_id' => $this->provider['actor'], 'rating_count' => 1]);
    }

    public function test_a_wrong_code_is_refused_and_five_wrong_ones_burn_the_code(): void
    {
        $order = $this->invite()->json('id');
        $right = $this->otp->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            $this->confirm($order, $this->provider, $wrong)->assertUnprocessable()->assertJsonPath('code', 'auth.otp_invalid');
        }
        $this->confirm($order, $this->provider, $right)->assertUnprocessable();
        $this->assertSame('invited', DB::table('orders')->where('id', $order)->value('status'));
    }

    public function test_a_login_code_cannot_confirm_a_past_job(): void
    {
        $order = $this->invite()->json('id');
        DB::table('otp_codes')->where('phone_e164', '+93771111111')->update(['purpose' => 'login']);

        $this->confirm($order, $this->provider, $this->otp->lastCode())->assertUnprocessable();
    }

    public function test_the_code_works_once_and_a_confirmed_job_is_final(): void
    {
        $order = $this->invite()->json('id');
        $code = $this->otp->lastCode();
        $this->confirm($order, $this->provider, $code)->assertOk();

        $this->confirm($order, $this->provider, $code)->assertStatus(409)->assertJsonPath('code', 'order.invalid_state');
        $this->as($this->provider)->postJson("/api/v1/orders/$order/invited-code")->assertStatus(409);
    }

    public function test_strangers_and_normal_orders_cannot_use_the_code_endpoints(): void
    {
        $order = $this->invite()->json('id');
        $normal = $this->order($this->person()['actor'], $this->provider['actor']);

        $this->confirm($order, $this->person(), '123456')->assertNotFound();
        $this->confirm($normal, $this->provider, '123456')->assertForbidden();
        $this->as($this->provider)->postJson("/api/v1/orders/$order/confirm-invited", ['code' => 'abc'])->assertUnprocessable();
    }

    public function test_the_code_can_be_typed_with_persian_digits(): void
    {
        $order = $this->invite()->json('id');
        $code = strtr($this->otp->lastCode(), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        $this->confirm($order, $this->provider, " $code ")->assertOk();
    }

    // ---------------------------------------------------------------- sending the code again

    public function test_the_provider_can_ask_for_a_new_code_and_only_the_newest_works(): void
    {
        $order = $this->invite()->json('id');
        $first = $this->otp->lastCode();
        $this->travel(2)->seconds();

        $this->as($this->provider)->postJson("/api/v1/orders/$order/invited-code")->assertNoContent();
        $this->assertCount(2, $this->otp->sent);
        if ($first !== $this->otp->lastCode()) {
            $this->confirm($order, $this->provider, $first)->assertUnprocessable();
        }
        $this->confirm($order, $this->provider, $this->otp->lastCode())->assertOk();
    }

    public function test_only_the_provider_can_resend_and_sending_is_rate_limited(): void
    {
        $order = $this->invite()->json('id');
        $user = DB::table('users')->where('phone_e164', '+93771111111')->first();
        $customer = ['user' => $user->id, 'actor' => DB::table('actors')->where('personal_user_id', $user->id)->value('id')];

        $this->as($customer)->postJson("/api/v1/orders/$order/invited-code")->assertForbidden();
        $this->as($this->provider)->postJson("/api/v1/orders/$order/invited-code")->assertNoContent();
        $this->as($this->provider)->postJson("/api/v1/orders/$order/invited-code")->assertNoContent();
        $this->as($this->provider)->postJson("/api/v1/orders/$order/invited-code")->assertStatus(429)->assertJsonPath('code', 'auth.otp_rate_limited');
    }

    public function test_when_the_code_cannot_be_sent_the_order_still_exists_and_says_so(): void
    {
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('otp:phone:+93771111111', 3600);   // the number already got its three codes this hour
        }

        $res = $this->invite()->assertCreated()->assertJsonPath('code_sent', false);

        $this->assertDatabaseHas('orders', ['id' => $res->json('id'), 'status' => 'invited']);
        $this->assertSame([], $this->otp->sent);
    }

    public function test_an_unconfirmed_past_job_does_not_count_for_the_profile_but_does_count_for_the_monthly_cap(): void
    {
        $this->invite()->assertCreated();

        $this->assertDatabaseMissing('actor_stats', ['actor_id' => $this->provider['actor']]);
        $this->getJson("/api/v1/actors/{$this->provider['actor']}")->assertJsonPath('stats.completed_count', 0);
    }

    public function test_the_prepared_customer_becomes_active_on_login(): void
    {
        $this->invite()->assertCreated();

        $this->postJson('/api/v1/auth/otp', ['phone' => '0771111111'])->assertNoContent();
        $this->postJson('/api/v1/auth/verify', ['phone' => '0771111111', 'code' => $this->otp->lastCode()])->assertOk();

        $this->assertDatabaseHas('users', ['phone_e164' => '+93771111111', 'status' => 'active']);
    }
}
