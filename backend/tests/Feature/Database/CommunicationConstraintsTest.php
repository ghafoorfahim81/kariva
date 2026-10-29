<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationConstraintsTest extends DatabaseTestCase
{
    // ---------------------------------------------------------------- reports

    private function report(string $user, string $type, string $id, array $o = []): string
    {
        return $this->insert('reports', $o + ['reporter_user_id' => $user, 'reportable_type' => $type, 'reportable_id' => $id, 'reason' => 'spam']);
    }

    public function test_a_user_has_one_open_report_per_subject(): void
    {
        $reporter = $this->person()['user'];
        $subject = $this->person()['actor'];
        $this->report($reporter, 'actor', $subject);

        $this->assertRejected(fn () => $this->report($reporter, 'actor', $subject), 'one_open_report_per_user');
        $this->report($this->person()['user'], 'actor', $subject);       // another user may report the same subject
        $this->report($reporter, 'actor', $this->person()['actor']);     // the same user may report another subject
        $this->report($reporter, 'message', $subject);                   // or the same id under another type
        $this->addToAssertionCount(1);
    }

    public function test_a_closed_report_lets_the_user_report_again(): void
    {
        $reporter = $this->person()['user'];
        $admin = $this->person()['user'];
        $subject = $this->person()['actor'];
        $first = $this->report($reporter, 'actor', $subject);

        DB::table('reports')->where('id', $first)->update(['status' => 'resolved', 'resolved_by' => $admin, 'resolved_at' => now()]);
        $this->report($reporter, 'actor', $subject);
        $this->addToAssertionCount(1);
    }

    public function test_report_type_status_and_resolution_are_validated(): void
    {
        $reporter = $this->person()['user'];
        $subject = (string) Str::uuid();

        foreach (['actor', 'service', 'review', 'message'] as $type) {
            $this->report($reporter, $type, $subject);
        }
        $this->assertRejected(fn () => $this->report($reporter, 'need', $subject), 'reports_type_valid');
        $this->assertRejected(fn () => $this->report($reporter, 'actor', $subject, ['status' => 'pending']), 'violates check constraint "reports_');
        $this->assertRejected(fn () => $this->report($reporter, 'actor', $subject, ['reason' => ' ']), 'reports_reason_not_blank');
        $this->assertRejected(fn () => $this->report($this->person()['user'], 'actor', $subject, ['status' => 'resolved']), 'reports_resolved_when_closed');
    }

    // ---------------------------------------------------------------- favorites

    public function test_a_favorite_is_unique_per_user_and_subject_and_limited_to_three_types(): void
    {
        $user = $this->person()['user'];
        $subject = (string) Str::uuid();
        $row = ['user_id' => $user, 'favoritable_type' => 'actor', 'favoritable_id' => $subject];
        DB::table('favorites')->insert($row);

        $this->assertRejected(fn () => DB::table('favorites')->insert($row), 'favorites_pkey');
        DB::table('favorites')->insert(['favoritable_type' => 'service'] + $row);
        DB::table('favorites')->insert(['favoritable_type' => 'need'] + $row);
        $this->assertRejected(fn () => DB::table('favorites')->insert(['favoritable_type' => 'post'] + $row), 'favorites_type_valid');
    }

    // ---------------------------------------------------------------- conversations

    private function pairKey(string $a, string $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function test_a_conversation_belongs_to_an_order_or_to_a_pair_never_both_or_neither(): void
    {
        $a = $this->person()['actor'];
        $b = $this->person()['actor'];
        $order = $this->order($a, $b);

        $this->insert('conversations', ['order_id' => $order]);
        $this->insert('conversations', ['pair_key' => $this->pairKey($a, $b)]);
        $this->assertRejected(fn () => $this->insert('conversations', []), 'conversations_order_xor_pair');
        $this->assertRejected(fn () => $this->insert('conversations', ['order_id' => $this->order($a, $b), 'pair_key' => $this->pairKey($a, $b).'x']), 'conversations_order_xor_pair');
    }

    public function test_one_conversation_per_order_and_one_direct_conversation_per_pair(): void
    {
        $a = $this->person()['actor'];
        $b = $this->person()['actor'];
        $order = $this->order($a, $b);
        $this->insert('conversations', ['order_id' => $order]);
        $this->insert('conversations', ['pair_key' => $this->pairKey($a, $b)]);

        $this->assertRejected(fn () => $this->insert('conversations', ['order_id' => $order]), 'conversations_order_unique');
        $this->assertRejected(fn () => $this->insert('conversations', ['pair_key' => $this->pairKey($a, $b)]), 'conversations_pair_key_unique');
    }

    public function test_the_pair_key_is_two_ordered_uuids(): void
    {
        $a = $this->person()['actor'];
        $b = $this->person()['actor'];
        [$low, $high] = [min($a, $b), max($a, $b)];

        $this->assertRejected(fn () => $this->insert('conversations', ['pair_key' => "$high:$low"]), 'conversations_pair_key_format');
        $this->assertRejected(fn () => $this->insert('conversations', ['pair_key' => "$low:$low"]), 'conversations_pair_key_format');
        $this->assertRejected(fn () => $this->insert('conversations', ['pair_key' => 'a:b']), 'conversations_pair_key_format');
        $this->insert('conversations', ['pair_key' => "$low:$high"]);
        $this->addToAssertionCount(1);
    }

    public function test_only_the_parties_of_an_order_join_its_conversation(): void
    {
        $customer = $this->person()['actor'];
        $provider = $this->person()['actor'];
        $conversation = $this->insert('conversations', ['order_id' => $this->order($customer, $provider)]);

        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $customer]);
        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $provider]);
        $this->assertRejected(fn () => DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $this->person()['actor']]), 'only the customer and provider');
    }

    public function test_only_the_two_actors_of_the_pair_join_a_direct_conversation(): void
    {
        $a = $this->person()['actor'];
        $b = $this->person()['actor'];
        $conversation = $this->insert('conversations', ['pair_key' => $this->pairKey($a, $b)]);

        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $a]);
        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $b]);
        $this->assertRejected(fn () => DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $this->person()['actor']]), 'not part of this conversation');
        $this->assertRejected(fn () => DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $a]), 'conversation_participants_pkey');
    }

    // ---------------------------------------------------------------- messages

    /** @return array{string, string, string} conversation, customer actor, provider actor */
    private function chat(): array
    {
        $customer = $this->person()['actor'];
        $provider = $this->person()['actor'];
        $conversation = $this->insert('conversations', ['order_id' => $this->order($customer, $provider)]);
        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $customer]);
        DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $provider]);

        return [$conversation, $customer, $provider];
    }

    public function test_only_participants_can_send_messages(): void
    {
        [$conversation, $customer] = $this->chat();

        $this->insert('messages', ['conversation_id' => $conversation, 'sender_actor_id' => $customer, 'body' => 'سلام']);
        $this->assertRejected(fn () => $this->insert('messages', ['conversation_id' => $conversation, 'sender_actor_id' => $this->person()['actor'], 'body' => 'سلام']), 'messages_sender_is_participant');
    }

    public function test_a_text_message_needs_a_body_but_a_media_message_does_not(): void
    {
        [$conversation, $customer] = $this->chat();
        $base = ['conversation_id' => $conversation, 'sender_actor_id' => $customer];

        $this->assertRejected(fn () => $this->insert('messages', $base), 'messages_text_has_body');
        $this->assertRejected(fn () => $this->insert('messages', $base + ['body' => '   ']), 'messages_text_has_body');
        $this->assertRejected(fn () => $this->insert('messages', $base + ['kind' => 'sticker', 'body' => 'x']), 'messages_kind_valid');
        $this->insert('messages', $base + ['kind' => 'media']);
        $this->addToAssertionCount(1);
    }

    public function test_the_conversation_read_marker_is_a_time_per_participant(): void
    {
        [$conversation, $customer] = $this->chat();

        DB::table('conversation_participants')->where(['conversation_id' => $conversation, 'actor_id' => $customer])->update(['last_read_at' => now()]);
        $this->assertNotNull(DB::table('conversation_participants')->where(['conversation_id' => $conversation, 'actor_id' => $customer])->value('last_read_at'));
    }

    // ---------------------------------------------------------------- notifications

    public function test_the_same_event_notifies_a_user_only_once(): void
    {
        $a = $this->person()['user'];
        $b = $this->person()['user'];
        $row = ['type' => 'order.quoted', 'dedupe_key' => 'order:1:quoted'];
        $this->insert('notifications', $row + ['user_id' => $a]);

        $this->assertRejected(fn () => $this->insert('notifications', $row + ['user_id' => $a]), 'notifications_dedupe_unique');
        $this->insert('notifications', $row + ['user_id' => $b]); // the other party of the same event is notified too
        $this->addToAssertionCount(1);
    }
}
