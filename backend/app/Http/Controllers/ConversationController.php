<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Support\Cursor;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Chat between actors: one conversation per order and one direct conversation per pair of actors.
 * In the MVP the client polls; the same endpoints will feed Reverb later.
 */
class ConversationController extends Controller
{
    private const MAX_BODY = 2000;

    /** My conversations, most recently active first, with unread counts. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('view', $actor);

        $rows = DB::select(<<<'SQL'
SELECT c.id, c.order_id, c.pair_key, c.last_message_at, c.created_at, me.last_read_at,
       o.id AS other_id, o.display_name AS other_name, o.type AS other_type,
       (SELECT count(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_actor_id <> me.actor_id
          AND (me.last_read_at IS NULL OR m.created_at > me.last_read_at)) AS unread,
       (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_body,
       ord.title_snapshot AS order_title, ord.status AS order_status
  FROM conversation_participants me
  JOIN conversations c ON c.id = me.conversation_id
  JOIN conversation_participants other ON other.conversation_id = c.id AND other.actor_id <> me.actor_id
  JOIN actors o ON o.id = other.actor_id
  LEFT JOIN orders ord ON ord.id = c.order_id
 WHERE me.actor_id = ?
 ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
 LIMIT 100
SQL, [$actor->id]);

        return response()->json(['data' => array_map(fn ($r) => [
            'id' => $r->id,
            'kind' => $r->order_id ? 'order' : 'direct',
            'order_id' => $r->order_id,
            'order_title' => $r->order_title,
            'order_status' => $r->order_status,
            'other' => ['id' => $r->other_id, 'display_name' => $r->other_name, 'type' => $r->other_type],
            'last_message' => $r->last_body,
            'last_message_at' => $r->last_message_at ? date(DATE_ATOM, strtotime($r->last_message_at)) : null,
            'unread' => (int) $r->unread,
        ], $rows)]);
    }

    /** Finds or opens the direct conversation with another actor (for example to talk about a job opportunity). */
    public function direct(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);
        $data = $request->validate(['actor_id' => ['required', 'uuid']]);
        $other = Actor::find($data['actor_id']);

        if (! $other || ! $other->isActive()) {
            throw new ApiException('conversation.actor_unavailable', 404);
        }
        if ($other->id === $actor->id) {
            throw new ApiException('conversation.with_self', 422);
        }

        [$a, $b] = [$actor->id, $other->id];
        $key = min($a, $b).':'.max($a, $b);

        $id = DB::transaction(function () use ($key, $a, $b) {
            $existing = DB::table('conversations')->where('pair_key', $key)->value('id');
            if ($existing) {
                return $existing;
            }
            $id = (string) Str::uuid();
            DB::table('conversations')->insert(['id' => $id, 'pair_key' => $key]);
            foreach ([$a, $b] as $actorId) {
                DB::table('conversation_participants')->insert(['conversation_id' => $id, 'actor_id' => $actorId]);
            }

            return $id;
        });

        return response()->json(['id' => $id, 'other' => ['id' => $other->id, 'display_name' => $other->display_name]]);
    }

    /** Messages newest first; `cursor` loads older ones. */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $actor = $this->participant($request, $conversation);
        Gate::authorize('view', $actor);
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['limit'] ?? 30);

        $query = DB::table('messages')->where('conversation_id', $conversation)->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'messages.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($m) => [
                'id' => $m->id,
                'sender_actor_id' => $m->sender_actor_id,
                'mine' => $m->sender_actor_id === $actor->id,
                'kind' => $m->kind,
                'body' => $m->body,
                'created_at' => date(DATE_ATOM, strtotime($m->created_at)),
            ]),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode(new \DateTimeImmutable($last->created_at), $last->id) : null,
        ]);
    }

    public function send(Request $request, string $conversation): JsonResponse
    {
        $actor = $this->participant($request, $conversation);
        Gate::authorize('update', $actor);
        $data = $request->validate(['body' => ['required', 'string', 'max:'.self::MAX_BODY]]);
        $body = trim($data['body']);
        if ($body === '') {
            throw new ApiException('message.empty', 422);
        }

        $id = (string) Str::uuid();
        $at = now()->format('Y-m-d H:i:s.uP');
        DB::transaction(function () use ($id, $at, $conversation, $actor, $request, $body) {
            DB::table('messages')->insert(['id' => $id, 'conversation_id' => $conversation, 'sender_actor_id' => $actor->id, 'sender_user_id' => $request->user()->id, 'kind' => 'text', 'body' => $body, 'created_at' => $at]);
            DB::table('conversations')->where('id', $conversation)->update(['last_message_at' => $at]);
            // the sender has read everything up to now
            DB::table('conversation_participants')->where(['conversation_id' => $conversation, 'actor_id' => $actor->id])->update(['last_read_at' => $at]);
        });

        $other = DB::table('conversation_participants')->where('conversation_id', $conversation)->where('actor_id', '!=', $actor->id)->value('actor_id');
        if ($other) {
            Notifier::actor($other, 'message.new', "message:$id", ['conversation_id' => $conversation, 'by' => $actor->display_name]);
        }

        return response()->json(['id' => $id, 'mine' => true, 'kind' => 'text', 'body' => $body, 'created_at' => date(DATE_ATOM, strtotime($at))], 201);
    }

    public function read(Request $request, string $conversation): JsonResponse
    {
        $actor = $this->participant($request, $conversation);
        DB::table('conversation_participants')->where(['conversation_id' => $conversation, 'actor_id' => $actor->id])->update(['last_read_at' => now()]);

        return response()->json(null, 204);
    }

    /** The acting actor must take part in the conversation; otherwise it looks as if it does not exist. */
    private function participant(Request $request, string $conversation): Actor
    {
        $actor = $request->attributes->get('actor');
        abort_unless(Str::isUuid($conversation) && DB::table('conversation_participants')->where(['conversation_id' => $conversation, 'actor_id' => $actor->id])->exists(), 404);

        return $actor;
    }
}
