<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class AuthTest extends ApiTestCase
{
    private const PHONE = '+93791234567';

    private function login(string $phone = '0791234567'): TestResponse
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => $phone])->assertNoContent();

        return $this->postJson('/api/v1/auth/verify', ['phone' => $phone, 'code' => $this->otp->lastCode()]);
    }

    public function test_first_login_creates_the_user_and_a_personal_actor(): void
    {
        $response = $this->login()->assertOk()->assertJsonPath('user.phone', self::PHONE);

        $user = User::where('phone_e164', self::PHONE)->firstOrFail();
        $actor = DB::table('actors')->where('personal_user_id', $user->id)->first();
        $this->assertSame('person', $actor->type);
        $this->assertDatabaseHas('actor_members', ['actor_id' => $actor->id, 'user_id' => $user->id, 'role' => 'owner', 'is_primary' => true]);
        $response->assertJsonPath('user.actors.0.id', $actor->id)->assertJsonPath('user.actors.0.is_personal', true);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_a_second_login_reuses_the_same_user_and_actor(): void
    {
        $this->login();
        $this->login('+93 79 123 4567');

        $this->assertSame(1, User::count());
        $this->assertSame(1, DB::table('actors')->count());
    }

    public function test_phone_can_be_typed_with_persian_digits(): void
    {
        $this->login('۰۷۹۱۲۳۴۵۶۷')->assertOk()->assertJsonPath('user.phone', self::PHONE);
    }

    public function test_code_can_be_typed_with_persian_digits_and_spaces(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $code = strtr($this->otp->lastCode(), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => ' '.$code.' '])->assertOk();
    }

    public function test_invalid_phones_are_rejected_before_any_code_is_sent(): void
    {
        foreach (['', 'abc', '0691234567', '+447911123456', '12345'] as $bad) {
            $this->postJson('/api/v1/auth/otp', ['phone' => $bad])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertSame([], $this->otp->sent);
    }

    public function test_sending_a_code_does_not_reveal_whether_the_number_is_registered(): void
    {
        $this->login();
        $known = $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $unknown = $this->postJson('/api/v1/auth/otp', ['phone' => '+93701111111']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_the_code_is_stored_hashed_with_a_five_minute_life(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $row = DB::table('otp_codes')->first();
        $this->assertStringNotContainsString($this->otp->lastCode(), $row->code_hash);
        $this->assertSame(6, strlen($this->otp->lastCode()));
        $this->assertEqualsWithDelta(5 * 60, strtotime($row->expires_at) - strtotime($row->created_at), 5);
    }

    public function test_a_wrong_code_is_refused_and_counts_an_attempt(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $wrong = $this->otp->lastCode() === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'auth.otp_invalid');
        $this->assertSame(1, (int) DB::table('otp_codes')->value('attempts'));
        $this->assertSame(0, User::count());
    }

    public function test_after_five_wrong_attempts_even_the_right_code_stops_working(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $right = $this->otp->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $wrong])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $right])->assertUnprocessable();
        $this->assertSame(0, User::count());
    }

    public function test_a_code_works_once(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $code = $this->otp->lastCode();

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $code])->assertUnprocessable();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $this->otp->lastCode()])->assertUnprocessable();
    }

    public function test_a_code_for_one_phone_does_not_work_for_another(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $this->postJson('/api/v1/auth/verify', ['phone' => '+93701111111', 'code' => $this->otp->lastCode()])->assertUnprocessable();
    }

    public function test_only_the_newest_code_counts(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $first = $this->otp->lastCode();
        $this->travel(1)->second();
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        if ($first !== $this->otp->lastCode()) {
            $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $first])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $this->otp->lastCode()])->assertOk();
    }

    public function test_at_most_three_codes_per_hour_per_phone(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertNoContent();
        }

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(429)->assertJsonPath('code', 'auth.otp_rate_limited');
        $this->assertCount(3, $this->otp->sent);
    }

    public function test_the_ip_limit_is_separate_and_configurable(): void
    {
        config(['kariva.otp.max_sends_per_hour_per_ip' => 2]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '+93701111111'])->assertNoContent();
        $this->postJson('/api/v1/auth/otp', ['phone' => '+93702222222'])->assertNoContent();
        $this->postJson('/api/v1/auth/otp', ['phone' => '+93703333333'])->assertStatus(429);
    }

    public function test_the_limit_resets_after_an_hour(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        }
        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertNoContent();
    }

    public function test_a_fake_code_works_in_development_but_never_in_production(): void
    {
        config(['kariva.otp.fake_code' => '123456']);
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);
        $this->assertSame('123456', $this->otp->lastCode());

        $this->app['env'] = 'production';
        $this->postJson('/api/v1/auth/otp', ['phone' => '+93701111111']);
        $this->assertNotSame('123456', $this->otp->lastCode());
    }

    public function test_a_suspended_or_deleted_user_cannot_log_in(): void
    {
        $suspended = $this->person(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/otp', ['phone' => DB::table('users')->where('id', $suspended['user'])->value('phone_e164')]);
        $this->postJson('/api/v1/auth/verify', ['phone' => DB::table('users')->where('id', $suspended['user'])->value('phone_e164'), 'code' => $this->otp->lastCode()])
            ->assertForbidden()->assertJsonPath('code', 'auth.account_unavailable');
    }

    public function test_an_invited_user_becomes_active_on_first_login(): void
    {
        $invited = $this->person(['status' => 'invited', 'phone_e164' => self::PHONE]);

        $this->login()->assertOk();

        $this->assertDatabaseHas('users', ['id' => $invited['user'], 'status' => 'active']);
        $this->assertSame(1, DB::table('actors')->count());
    }

    // ---------------------------------------------------------------- sessions and tokens

    public function test_the_browser_spa_gets_a_session_cookie_not_a_token(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost:5173', 'Origin' => 'http://localhost:5173']);
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $response = $this->postJson('/api/v1/auth/verify', ['phone' => self::PHONE, 'code' => $this->otp->lastCode()])->assertOk();

        $response->assertJsonMissingPath('token');
        $this->assertAuthenticated('web');
    }

    public function test_a_client_without_a_session_gets_a_token_that_opens_me(): void
    {
        $token = $this->login()->assertOk()->json('token');
        $this->assertNotEmpty($token);

        $this->flushHeaders()->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('phone', self::PHONE);
    }

    public function test_me_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_lists_every_actor_with_the_role_of_the_user(): void
    {
        $person = $this->person();
        $page = $this->business($person);
        $other = $this->business();
        $this->member($other['actor'], 'viewer');
        DB::table('actor_members')->insert(['actor_id' => $other['actor'], 'user_id' => $person['user'], 'role' => 'viewer']);

        $actors = $this->actingAsUser($person['user'])->getJson('/api/v1/me')->assertOk()->json('actors');

        $this->assertCount(3, $actors);
        $byId = collect($actors)->keyBy('id');
        $this->assertSame('owner', $byId[$page['actor']]['role']);
        $this->assertTrue($byId[$page['actor']]['is_primary']);
        $this->assertSame('viewer', $byId[$other['actor']]['role']);
        $this->assertFalse($byId[$other['actor']]['is_primary']);
        $this->assertTrue($byId[$person['actor']]['is_personal']);
    }

    public function test_logout_closes_the_token(): void
    {
        $token = $this->login()->json('token');
        $this->flushHeaders()->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}
