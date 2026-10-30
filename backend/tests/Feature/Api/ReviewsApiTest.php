<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReviewsApiTest extends ApiTestCase
{
    private array $customer;

    private array $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = $this->person();
        $this->provider = $this->business();
    }

    private function as(array $who): static
    {
        return $this->actingAsUser($who['user'], $who['actor']);
    }

    private function completed(?array $customer = null, ?array $provider = null): string
    {
        $c = $customer ?? $this->customer;
        $p = $provider ?? $this->provider;
        $id = $this->order($c['actor'], $p['actor']);
        $this->advance($id, 'completed');

        return $id;
    }

    private function review(string $order, array $o = [], ?array $who = null)
    {
        return $this->as($who ?? $this->customer)->postJson("/api/v1/orders/$order/review", $o + ['stars' => 5, 'tags' => ['on_time', 'clean'], 'body' => 'کار عالی']);
    }

    // ---------------------------------------------------------------- writing

    public function test_the_customer_reviews_a_completed_order_and_the_stats_follow(): void
    {
        $order = $this->completed();

        $this->review($order)->assertCreated()->assertJsonPath('stars', 5)->assertJsonPath('tags', ['on_time', 'clean'])->assertJsonPath('body', 'کار عالی')->assertJsonPath('reply', null);

        $this->assertDatabaseHas('actor_stats', ['actor_id' => $this->provider['actor'], 'rating_count' => 1, 'rating_avg' => 5.00, 'completed_count' => 1]);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->provider['user'], 'type' => 'review.received']);
    }

    public function test_the_average_is_recomputed_from_all_reviews(): void
    {
        foreach ([5, 4, 4] as $stars) {
            $this->review($this->completed(), ['stars' => $stars])->assertCreated();
        }

        $this->assertDatabaseHas('actor_stats', ['actor_id' => $this->provider['actor'], 'rating_count' => 3, 'rating_avg' => 4.33]);
        $this->getJson("/api/v1/actors/{$this->provider['actor']}")->assertJsonPath('stats.rating_avg', 4.33)->assertJsonPath('stats.rating_count', 3);
    }

    public function test_no_review_before_completion(): void
    {
        foreach (['requested', 'quoted', 'accepted', 'provider_done'] as $status) {
            $id = $this->order($this->customer['actor'], $this->provider['actor']);
            if ($status !== 'requested') {
                $this->advance($id, $status);
            }
            $this->review($id)->assertStatus(409)->assertJsonPath('code', 'review.order_not_completed');
        }
    }

    public function test_one_review_per_order(): void
    {
        $order = $this->completed();
        $this->review($order)->assertCreated();

        $this->review($order, ['stars' => 1])->assertStatus(409)->assertJsonPath('code', 'review.exists');
        $this->assertSame(1, DB::table('reviews')->count());
    }

    public function test_only_the_customer_reviews_and_strangers_see_nothing(): void
    {
        $order = $this->completed();

        $this->review($order, [], $this->provider)->assertForbidden()->assertJsonPath('code', 'review.wrong_side');
        $this->review($order, [], $this->person())->assertNotFound();
        $page = $this->business($this->customer);
        $viewer = $this->member($page['actor'], 'viewer');
        $this->actingAsUser($viewer, $page['actor'])->postJson("/api/v1/orders/$order/review", ['stars' => 5])->assertNotFound();   // not a party
    }

    public function test_a_viewer_of_the_customer_page_cannot_review(): void
    {
        $page = $this->business($this->customer);
        $order = $this->order($page['actor'], $this->provider['actor']);
        $this->advance($order, 'completed');
        $viewer = $this->member($page['actor'], 'viewer');
        $editor = $this->member($page['actor'], 'editor');

        $this->actingAsUser($viewer, $page['actor'])->postJson("/api/v1/orders/$order/review", ['stars' => 4])->assertForbidden();
        $this->actingAsUser($editor, $page['actor'])->postJson("/api/v1/orders/$order/review", ['stars' => 4])->assertCreated();
    }

    public function test_values_are_validated(): void
    {
        $order = $this->completed();

        $this->review($order, ['stars' => 0])->assertUnprocessable();
        $this->review($order, ['stars' => 6])->assertUnprocessable();
        $this->review($order, ['stars' => 'x'])->assertUnprocessable();
        $this->review($order, ['tags' => ['rude']])->assertUnprocessable();
        $this->review($order, ['tags' => ['clean', 'clean']])->assertUnprocessable();
        $this->review($order, ['body' => str_repeat('ا', 1001)])->assertUnprocessable();
        $this->review($order, ['tags' => null, 'body' => null])->assertCreated();
    }

    public function test_the_order_shows_its_review(): void
    {
        $order = $this->completed();
        $this->review($order)->assertCreated();

        $this->as($this->customer)->getJson("/api/v1/orders/$order")->assertJsonPath('review.stars', 5);
    }

    // ---------------------------------------------------------------- reply

    public function test_the_provider_replies_once(): void
    {
        $review = $this->review($this->completed())->json('id');

        $this->as($this->customer)->postJson("/api/v1/reviews/$review/reply", ['body' => 'خودم'])->assertForbidden();
        $this->as($this->provider)->postJson("/api/v1/reviews/$review/reply", ['body' => 'ممنون از شما'])->assertOk()->assertJsonPath('reply.body', 'ممنون از شما');
        $this->as($this->provider)->postJson("/api/v1/reviews/$review/reply", ['body' => 'دوباره'])->assertStatus(409)->assertJsonPath('code', 'review.already_replied');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->customer['user'], 'type' => 'review.replied']);
    }

    public function test_a_reply_needs_text_and_editor_rights(): void
    {
        $review = $this->review($this->completed())->json('id');
        $viewer = $this->member($this->provider['actor'], 'viewer');

        $this->as($this->provider)->postJson("/api/v1/reviews/$review/reply", ['body' => ''])->assertUnprocessable();
        $this->actingAsUser($viewer, $this->provider['actor'])->postJson("/api/v1/reviews/$review/reply", ['body' => 'سلام'])->assertForbidden();
    }

    // ---------------------------------------------------------------- public list

    public function test_reviews_of_an_actor_are_public_newest_first_and_paged(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $ids[] = $this->review($this->completed(), ['stars' => $i, 'body' => "نظر $i"])->json('id');
            $this->travel(1)->minute();
        }
        auth()->forgetGuards();

        $first = $this->getJson("/api/v1/actors/{$this->provider['actor']}/reviews?limit=2")->assertOk();
        $second = $this->getJson("/api/v1/actors/{$this->provider['actor']}/reviews?limit=2&cursor=".$first->json('next_cursor'))->assertOk();

        $this->assertSame([$ids[2], $ids[1], $ids[0]], array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->assertNull($second->json('next_cursor'));
        $this->assertNotNull($first->json('data.0.customer.display_name'));
        $this->getJson("/api/v1/actors/{$this->provider['actor']}/reviews?cursor=zz")->assertUnprocessable();
    }

    public function test_a_review_never_exposes_the_customer_phone_or_user_ids(): void
    {
        $this->review($this->completed())->assertCreated();

        $json = $this->getJson("/api/v1/actors/{$this->provider['actor']}/reviews")->getContent();
        $this->assertStringNotContainsString($this->customer['user'], $json);
        $this->assertStringNotContainsString('+937', $json);
    }

    // ---------------------------------------------------------------- reports and favorites

    public function test_a_user_reports_something_once_until_it_is_handled(): void
    {
        $user = $this->person();
        $target = $this->provider['actor'];
        $body = ['type' => 'actor', 'id' => $target, 'reason' => 'اسپم'];

        $this->actingAsUser($user['user'])->postJson('/api/v1/reports', $body)->assertCreated()->assertJsonPath('status', 'open');
        $this->actingAsUser($user['user'])->postJson('/api/v1/reports', $body)->assertStatus(409)->assertJsonPath('code', 'report.already_open');
        $this->actingAsUser($this->person()['user'])->postJson('/api/v1/reports', $body)->assertCreated();
        $this->assertSame(2, DB::table('reports')->count());
    }

    public function test_reports_cover_four_kinds_and_check_the_target(): void
    {
        $this->postJson('/api/v1/reports', [])->assertUnauthorized();
        $user = $this->person()['user'];
        $review = $this->review($this->completed())->json('id');
        $service = $this->service($this->provider['actor']);

        foreach ([['review', $review], ['service', $service]] as [$type, $id]) {
            $this->actingAsUser($user)->postJson('/api/v1/reports', ['type' => $type, 'id' => $id, 'reason' => 'نامناسب'])->assertCreated();
        }
        $this->actingAsUser($user)->postJson('/api/v1/reports', ['type' => 'service', 'id' => (string) Str::uuid(), 'reason' => 'x y'])->assertNotFound();
        $this->actingAsUser($user)->postJson('/api/v1/reports', ['type' => 'need', 'id' => $service, 'reason' => 'x y'])->assertUnprocessable();
        $this->actingAsUser($user)->postJson('/api/v1/reports', ['type' => 'actor', 'id' => $service])->assertUnprocessable();
    }

    public function test_favorites_are_added_listed_and_removed(): void
    {
        $user = $this->person()['user'];
        $service = $this->service($this->provider['actor']);
        $base = fn (string $type, string $id) => "/api/v1/favorites/$type/$id";

        $this->actingAsUser($user)->putJson($base('actor', $this->provider['actor']))->assertCreated();
        $this->actingAsUser($user)->putJson($base('actor', $this->provider['actor']))->assertCreated();   // again: no error, no duplicate
        $this->actingAsUser($user)->putJson($base('service', $service))->assertCreated();

        $this->assertCount(2, $this->actingAsUser($user)->getJson('/api/v1/favorites')->json('data'));
        $this->actingAsUser($user)->deleteJson($base('actor', $this->provider['actor']))->assertNoContent();
        $this->assertCount(1, $this->actingAsUser($user)->getJson('/api/v1/favorites')->json('data'));
        $this->assertCount(0, $this->actingAsUser($this->person()['user'])->getJson('/api/v1/favorites')->json('data'));
    }

    public function test_favorites_check_type_and_target(): void
    {
        $user = $this->person()['user'];

        $this->actingAsUser($user)->putJson('/api/v1/favorites/post/'.Str::uuid())->assertNotFound();
        $this->actingAsUser($user)->putJson('/api/v1/favorites/actor/'.Str::uuid())->assertNotFound();
        $this->actingAsUser($user)->putJson('/api/v1/favorites/actor/not-a-uuid')->assertNotFound();
    }
}
