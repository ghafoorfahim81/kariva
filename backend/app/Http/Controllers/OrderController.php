<?php

namespace App\Http\Controllers;

use App\Actions\Orders\OrderFlow;
use App\Http\Requests\QuoteRequest;
use App\Http\Resources\OrderResource;
use App\Models\Actor;
use App\Models\Order;
use App\Support\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** All routes run as the actor named in X-Actor-Id (middleware `actor`). Policies decide from actor_members. */
class OrderController extends Controller
{
    private const LIST_LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::authorize('view', $actor);
        $data = $request->validate([
            'role' => ['nullable', Rule::in(['customer', 'provider'])],
            'status' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? self::LIST_LIMIT);

        $query = Order::query()->with(['customer', 'provider', 'pendingQuote'])
            ->where(function ($q) use ($actor, $data) {
                $role = $data['role'] ?? null;
                if ($role !== 'provider') {
                    $q->orWhere('customer_actor_id', $actor->id);
                }
                if ($role !== 'customer') {
                    $q->orWhere('provider_actor_id', $actor->id);
                }
            })
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'orders.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }

        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => OrderResource::collection($items),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode($last->created_at, $last->id) : null,
        ]);
    }

    public function store(Request $request, OrderFlow $flow): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::authorize('update', $actor);
        $data = $request->validate([
            'service_id' => ['nullable', 'uuid', 'required_without:provider_actor_id'],
            'provider_actor_id' => ['nullable', 'uuid', 'required_without:service_id'],
            'title' => ['nullable', 'string', 'min:2', 'max:150', 'required_without:service_id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $flow->create($actor, $data);

        return $this->respond($request, $order, 201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $this->party($request, $order);
        Gate::authorize('view', $this->actor($request));

        return $this->resource($request, $order);
    }

    public function quote(QuoteRequest $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->quote($order, $a, $request->validated()));
    }

    public function accept(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->accept($order, $a));
    }

    public function reject(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->reject($order, $a));
    }

    public function cancel(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->cancel($order, $a));
    }

    public function done(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->done($order, $a));
    }

    public function confirm(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->confirm($order, $a));
    }

    public function dispute(Request $request, Order $order, OrderFlow $flow): JsonResponse
    {
        return $this->act($request, $order, fn ($a) => $flow->dispute($order, $a));
    }

    // ---------------------------------------------------------------- helpers

    private function actor(Request $request): Actor
    {
        return $request->attributes->get('actor');
    }

    /** The acting actor must be one of the two parties; anyone else gets 404, as if the order did not exist. */
    private function party(Request $request, Order $order): void
    {
        $id = $this->actor($request)->id;
        abort_unless(in_array($id, [$order->customer_actor_id, $order->provider_actor_id], true), 404);
    }

    /** Changing an order needs editor rights or more on the acting actor. */
    private function act(Request $request, Order $order, callable $do): JsonResponse
    {
        $this->party($request, $order);
        Gate::authorize('update', $this->actor($request));

        return $this->respond($request, $do($this->actor($request)));
    }

    private function respond(Request $request, Order $order, int $status = 200): JsonResponse
    {
        return $this->resource($request, $order)->response()->setStatusCode($status);
    }

    private function resource(Request $request, Order $order): OrderResource
    {
        return new OrderResource($order->load(['customer', 'provider', 'pendingQuote', 'quotes', 'events', 'conversation', 'review']));
    }
}
