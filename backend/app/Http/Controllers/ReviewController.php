<?php

namespace App\Http\Controllers;

use App\Actions\Stats\RefreshActorStats;
use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Order;
use App\Models\Review;
use App\Support\Cursor;
use App\Support\Notifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Reviews exist only for completed orders, one per order, written by the customer; the provider may reply once. */
class ReviewController extends Controller
{
    public function store(Request $request, Order $order, RefreshActorStats $stats): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        abort_unless(in_array($actor->id, [$order->customer_actor_id, $order->provider_actor_id], true), 404);
        Gate::authorize('update', $actor);
        if ($actor->id !== $order->customer_actor_id) {
            throw new ApiException('review.wrong_side', 403);
        }
        if ($order->status !== 'completed') {
            throw new ApiException('review.order_not_completed', 409);
        }

        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => ['string', Rule::in(config('kariva.review_tags')), 'distinct'],
            'body' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $review = DB::transaction(function () use ($order, $data, $stats) {
                $review = Review::create([
                    'order_id' => $order->id,
                    'customer_actor_id' => $order->customer_actor_id,
                    'provider_actor_id' => $order->provider_actor_id,
                    'stars' => $data['stars'],
                    'tags' => array_values($data['tags'] ?? []),
                    'body' => $data['body'] ?? null,
                ])->refresh();
                $stats($order->provider_actor_id);

                return $review;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'reviews_order_unique')) {
                throw new ApiException('review.exists', 409);
            }
            throw $e;
        }

        Notifier::actor($order->provider_actor_id, 'review.received', "review:{$review->id}:received", ['order_id' => $order->id, 'review_id' => $review->id, 'stars' => $review->stars]);

        return response()->json($this->data($review), 201);
    }

    /** The provider answers once. A review cannot be reported by its own author and cannot be deleted by the provider. */
    public function reply(Request $request, Review $review): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);
        if ($review->provider_actor_id !== $actor->id) {
            throw new ApiException('review.wrong_side', 403);
        }
        if ($review->reply_body !== null) {
            throw new ApiException('review.already_replied', 409);
        }
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']]);

        $review->update(['reply_body' => $data['body'], 'replied_at' => now()]);
        Notifier::actor($review->customer_actor_id, 'review.replied', "review:{$review->id}:replied", ['order_id' => $review->order_id]);

        return response()->json($this->data($review->refresh()));
    }

    /** Public: reviews of an actor, newest first. */
    public function index(Request $request, Actor $actor): JsonResponse
    {
        abort_unless($actor->isActive(), 404);
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $limit = (int) ($data['limit'] ?? 20);

        $query = Review::query()->where('provider_actor_id', $actor->id)->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'reviews.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $customers = Actor::whereIn('id', $items->pluck('customer_actor_id'))->get()->keyBy('id');
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($r) => $this->data($r) + ['customer' => ['id' => $r->customer_actor_id, 'display_name' => $customers[$r->customer_actor_id]->display_name ?? null]]),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode($last->created_at, $last->id) : null,
        ]);
    }

    private function data(Review $r): array
    {
        return [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'stars' => $r->stars,
            'tags' => $r->tags ?? [],
            'body' => $r->body,
            'reply' => $r->reply_body === null ? null : ['body' => $r->reply_body, 'at' => $r->replied_at?->toIso8601String()],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
