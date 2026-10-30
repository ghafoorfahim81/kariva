<?php

namespace Tests\Feature\Api;

use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatApiTest extends ApiTestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->person();
        $this->b = $this->business();
    }

    private function as(array $who): static
    {
        return $this->actingAsUser($who['user'], $who['actor']);
    }

    private function direct(array $from, string $to): string
    {
        return $this->as($from)->postJson('/api/v1/conversations/direct', ['actor_id' => $to])->assertOk()->json('id');
    }

    private function say(array $who, string $conversation, string $body = 'سلام')
    {
        return $this->as($who)->postJson("/api/v1/conversations/$conversation/messages", ['body' => $body]);
    }

    // ---------------------------------------------------------------- direct conversations

    public function test_opening_a_direct_conversation_is_idempotent_and_symmetric(): void
    {
        $one = $this->direct($this->a, $this->b['actor']);
        $again = $this->direct($this->a, $this->b['actor']);
        $fromOther = $this->direct($this->b, $this->a['actor']);

        $this->assertSame($one, $again);
        $this->assertSame($one, $fromOther);
        $this->assertSame(1, DB::table('conversations')->whereNotNull('pair_key')->count());
        $this->assertSame(2, DB::table('conversation_participants')->where('conversation_id', $one)->count());
    }

    public function test_you_cannot_chat_with_yourself_or_a_missing_or_suspended_actor(): void
    {
        $this->as($this->a)->postJson('/api/v1/conversations/direct', ['actor_id' => $this->a['actor']])->assertUnprocessable()->assertJsonPath('code', 'conversation.with_self');
        $this->as($this->a)->postJson('/api/v1/conversations/direct', ['actor_id' => (string) Str::uuid()])->assertNotFound();
        DB::table('actors')->where('id', $this->b['actor'])->update(['status' => 'suspended']);
        $this->as($this->a)->postJson('/api/v1/conversations/direct', ['actor_id' => $this->b['actor']])->assertNotFound();
        $this->as($this->a)->postJson('/api/v1/conversations/direct', [])->assertUnprocessable();
    }

    public function test_a_viewer_cannot_start_a_conversation(): void
    {
        $viewer = $this->member($this->b['actor'], 'viewer');

        $this->actingAsUser($viewer, $this->b['actor'])->postJson('/api/v1/conversations/direct', ['actor_id' => $this->a['actor']])->assertForbidden();
    }

    // ---------------------------------------------------------------- sending and reading

    public function test_messages_go_both_ways_newest_first_with_a_mine_flag(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        $this->say($this->a, $c, 'یک')->assertCreated();
        $this->travel(1)->second();
        $this->say($this->b, $c, 'دو')->assertCreated();
        $this->travel(1)->second();
        $this->say($this->a, $c, 'سه')->assertCreated();

        $list = $this->as($this->a)->getJson("/api/v1/conversations/$c/messages")->assertOk()->json('data');
        $this->assertSame(['سه', 'دو', 'یک'], array_column($list, 'body'));
        $this->assertSame([true, false, true], array_column($list, 'mine'));
    }

    public function test_messages_are_paged_with_a_cursor(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        foreach (range(1, 5) as $i) {
            $this->say($this->a, $c, "پیام $i")->assertCreated();
            $this->travel(1)->second();
        }

        $first = $this->as($this->a)->getJson("/api/v1/conversations/$c/messages?limit=2")->assertOk();
        $second = $this->as($this->a)->getJson("/api/v1/conversations/$c/messages?limit=2&cursor=".$first->json('next_cursor'))->assertOk();
        $third = $this->as($this->a)->getJson("/api/v1/conversations/$c/messages?limit=2&cursor=".$second->json('next_cursor'))->assertOk();

        $all = array_merge(array_column($first->json('data'), 'body'), array_column($second->json('data'), 'body'), array_column($third->json('data'), 'body'));
        $this->assertSame(['پیام 5', 'پیام 4', 'پیام 3', 'پیام 2', 'پیام 1'], $all);
        $this->assertNull($third->json('next_cursor'));
        $this->as($this->a)->getJson("/api/v1/conversations/$c/messages?cursor=bad")->assertUnprocessable();
    }

    public function test_only_participants_can_read_or_write(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        $outsider = $this->person();

        $this->as($outsider)->getJson("/api/v1/conversations/$c/messages")->assertNotFound();
        $this->say($outsider, $c)->assertNotFound();
        $this->as($outsider)->postJson("/api/v1/conversations/$c/read")->assertNotFound();
        $this->actingAsUser($outsider['user'], $this->b['actor'])->getJson("/api/v1/conversations/$c/messages")->assertForbidden();   // not in that team
        $this->as($this->a)->getJson('/api/v1/conversations/not-a-uuid/messages')->assertNotFound();
    }

    public function test_a_message_needs_text_and_viewers_cannot_send(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        $viewer = $this->member($this->b['actor'], 'viewer');

        $this->say($this->a, $c, '')->assertUnprocessable();
        $this->say($this->a, $c, "   \n ")->assertUnprocessable();   // trimmed to empty by the framework
        $this->say($this->a, $c, str_repeat('ا', 2001))->assertUnprocessable();
        $this->actingAsUser($viewer, $this->b['actor'])->postJson("/api/v1/conversations/$c/messages", ['body' => 'سلام'])->assertForbidden();
        $this->actingAsUser($viewer, $this->b['actor'])->getJson("/api/v1/conversations/$c/messages")->assertOk();   // viewing is allowed
        $this->assertSame(0, DB::table('messages')->count());
    }

    public function test_the_team_of_a_page_shares_its_conversations(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        $this->say($this->a, $c, 'سلام به شرکت')->assertCreated();
        $editor = $this->member($this->b['actor'], 'editor');

        $this->actingAsUser($editor, $this->b['actor'])->getJson("/api/v1/conversations/$c/messages")->assertOk()->assertJsonPath('data.0.body', 'سلام به شرکت');
        $this->actingAsUser($editor, $this->b['actor'])->postJson("/api/v1/conversations/$c/messages", ['body' => 'جواب از تیم'])->assertCreated();
        $this->assertSame($editor, DB::table('messages')->where('body', 'جواب از تیم')->value('sender_user_id'));
    }

    public function test_the_other_side_is_notified_once_per_message(): void
    {
        $editor = $this->member($this->b['actor'], 'editor');
        $c = $this->direct($this->a, $this->b['actor']);
        $id = $this->say($this->a, $c)->json('id');

        $this->assertEqualsCanonicalizing([$this->b['user'], $editor], DB::table('notifications')->where('type', 'message.new')->pluck('user_id')->all());
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->a['user'], 'type' => 'message.new']);
        $this->assertDatabaseHas('notifications', ['dedupe_key' => "message:$id"]);
    }

    public function test_unread_counts_and_read_markers(): void
    {
        $c = $this->direct($this->a, $this->b['actor']);
        $this->say($this->b, $c, 'اول')->assertCreated();
        $this->travel(1)->second();
        $this->say($this->b, $c, 'دوم')->assertCreated();

        $mine = $this->as($this->a)->getJson('/api/v1/conversations')->assertOk()->json('data.0');
        $this->assertSame(2, $mine['unread']);
        $this->assertSame('دوم', $mine['last_message']);
        $this->assertSame($this->b['actor'], $mine['other']['id']);
        $this->assertSame(0, $this->as($this->b)->getJson('/api/v1/conversations')->json('data.0.unread'));   // the sender has read their own

        $this->travel(1)->second();
        $this->as($this->a)->postJson("/api/v1/conversations/$c/read")->assertNoContent();
        $this->assertSame(0, $this->as($this->a)->getJson('/api/v1/conversations')->json('data.0.unread'));

        $this->travel(1)->second();
        $this->say($this->b, $c, 'سوم')->assertCreated();
        $this->assertSame(1, $this->as($this->a)->getJson('/api/v1/conversations')->json('data.0.unread'));
    }

    public function test_the_list_shows_order_conversations_with_their_title_and_sorts_by_activity(): void
    {
        $order = $this->order($this->a['actor'], $this->b['actor'], ['title_snapshot' => 'نصب برق']);
        $conv = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $conv, 'order_id' => $order]);
        foreach ([$this->a['actor'], $this->b['actor']] as $actor) {
            DB::table('conversation_participants')->insert(['conversation_id' => $conv, 'actor_id' => $actor]);
        }
        $direct = $this->direct($this->a, $this->person()['actor']);
        $this->travel(1)->minute();
        $this->say($this->a, $conv, 'درباره سفارش')->assertCreated();

        $rows = $this->as($this->a)->getJson('/api/v1/conversations')->json('data');
        $this->assertSame([$conv, $direct], array_column($rows, 'id'));
        $this->assertSame('order', $rows[0]['kind']);
        $this->assertSame('نصب برق', $rows[0]['order_title']);
        $this->assertSame('direct', $rows[1]['kind']);
    }

    public function test_conversations_need_login_and_the_actor_header(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $this->actingAsUser($this->a['user'])->getJson('/api/v1/conversations')->assertStatus(400);
    }

    // ---------------------------------------------------------------- notifications

    public function test_notifications_are_listed_counted_and_marked_read(): void
    {
        $user = $this->a['user'];
        foreach (['order.quoted', 'order.accepted', 'message.new'] as $i => $type) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => $user, 'type' => $type, 'dedupe_key' => "k$i", 'data' => json_encode(['order_id' => 'x']), 'created_at' => now()->addSeconds($i)]);
        }
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->b['user'], 'type' => 'order.quoted', 'dedupe_key' => 'k9', 'data' => '{}']);

        $res = $this->actingAsUser($user)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(['message.new', 'order.accepted', 'order.quoted'], array_column($res->json('data'), 'type'));
        $this->assertSame(3, $res->json('unread_count'));
        $this->assertSame('x', $res->json('data.0.data.order_id'));

        $first = $res->json('data.0.id');
        $this->actingAsUser($user)->postJson("/api/v1/notifications/$first/read")->assertNoContent();
        $this->assertSame(2, $this->actingAsUser($user)->getJson('/api/v1/notifications')->json('unread_count'));
        $this->assertCount(2, $this->actingAsUser($user)->getJson('/api/v1/notifications?unread=1')->json('data'));
        $this->actingAsUser($user)->postJson('/api/v1/notifications/read-all')->assertNoContent();
        $this->assertSame(0, $this->actingAsUser($user)->getJson('/api/v1/notifications')->json('unread_count'));
    }

    public function test_notifications_are_private_and_paged(): void
    {
        $user = $this->a['user'];
        foreach (range(1, 5) as $i) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => $user, 'type' => 'order.quoted', 'dedupe_key' => "k$i", 'data' => '{}', 'created_at' => now()->addSeconds($i)]);
        }
        $theirs = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $theirs, 'user_id' => $this->b['user'], 'type' => 'order.quoted', 'dedupe_key' => 'z', 'data' => '{}']);

        $this->actingAsUser($user)->postJson("/api/v1/notifications/$theirs/read")->assertNotFound();
        $first = $this->actingAsUser($user)->getJson('/api/v1/notifications?limit=3')->assertOk();
        $second = $this->actingAsUser($user)->getJson('/api/v1/notifications?limit=3&cursor='.$first->json('next_cursor'))->assertOk();
        $this->assertCount(3, $first->json('data'));
        $this->assertCount(2, $second->json('data'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertDatabaseHas('notifications', ['id' => $theirs, 'read_at' => null]);
    }

    public function test_the_same_event_never_notifies_twice(): void
    {
        Notifier::user($this->a['user'], 'order.quoted', 'order:1:quote:1');
        Notifier::user($this->a['user'], 'order.quoted', 'order:1:quote:1');

        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->a['user'])->count());
    }

    public function test_notifications_need_login(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
