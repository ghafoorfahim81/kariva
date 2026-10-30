<?php

namespace App\Http\Controllers;

use App\Actions\Needs\OfferFlow;
use App\Http\Resources\OrderResource;
use App\Models\Actor;
use App\Models\Need;
use App\Models\Offer;
use App\Support\Cursor;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Needs (what customers are looking for) and the offers specialists send. */
class NeedController extends Controller
{
    /** Public board: open needs, newest first. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(['service', 'job'])],
            'category' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->page($request, Need::query()
            ->where('status', 'open')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['city'] ?? null, fn ($q, $c) => $q->where('city_id', $c))
            ->when($data['category'] ?? null, function ($q, $c) {
                $root = DB::table('categories')->where(Str::isUuid($c) ? 'id' : 'slug', $c)->value('id');
                $ids = $root ? DB::table('categories')->where('id', $root)->orWhere('parent_id', $root)->pluck('id') : collect();

                return $q->whereIn('category_id', $ids);
            }), $data);
    }

    /** Needs of the acting actor, any status. */
    public function mine(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('view', $actor);
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return $this->page($request, Need::query()->where('actor_id', $actor->id), $data, withOfferCount: true);
    }

    public function show(Request $request, Need $need): JsonResponse
    {
        $actor = $request->user('sanctum') ? $this->maybeActor($request) : null;
        $owner = $actor && $actor->id === $need->actor_id;
        abort_unless($need->isOpen() || $owner, 404);

        return response()->json($this->needData($need->load(['actor', 'category', 'city']), $owner));
    }

    public function store(Request $request, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['service', 'job'])],
            'category_id' => ['required', 'uuid', Rule::exists('categories', 'id')->where('is_active', true)],
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $need = $flow->openNeed($actor, $data);

        return response()->json($this->needData($need->load(['actor', 'category', 'city']), true), 201);
    }

    public function close(Request $request, Need $need, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);

        return response()->json($this->needData($flow->closeNeed($need, $actor)->load(['actor', 'category', 'city']), true));
    }

    // ---------------------------------------------------------------- offers

    /** The need owner sees every offer; a specialist sees only its own. */
    public function offers(Request $request, Need $need): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('view', $actor);
        $owner = $actor->id === $need->actor_id;

        $offers = Offer::with('provider')->where('need_id', $need->id)
            ->when(! $owner, fn ($q) => $q->where('provider_actor_id', $actor->id))->orderBy('created_at')->get();

        return response()->json(['data' => $offers->map(fn ($o) => $this->offerData($o))]);
    }

    public function offer(Request $request, Need $need, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);
        $request->merge(['amount' => Money::clean($request->input('amount'))]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'currency' => ['required', Rule::in(config('kariva.currencies'))],
            'price_unit' => ['required', Rule::in(['hour', 'day', 'meter', 'project', 'piece'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($this->offerData($flow->offer($need, $actor, $data)->load('provider')), 201);
    }

    public function withdraw(Request $request, Offer $offer, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);

        return response()->json($this->offerData($flow->withdraw($offer, $actor)->load('provider')));
    }

    public function rejectOffer(Request $request, Offer $offer, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);

        return response()->json($this->offerData($flow->reject($offer, $actor)->load('provider')));
    }

    /** Accepting returns the new order. */
    public function acceptOffer(Request $request, Offer $offer, OfferFlow $flow): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('update', $actor);
        $order = $flow->accept($offer, $actor);

        return (new OrderResource($order->load(['customer', 'provider', 'pendingQuote', 'quotes', 'events', 'conversation', 'review'])))->response()->setStatusCode(201);
    }

    // ---------------------------------------------------------------- helpers

    private function page(Request $request, $query, array $data, bool $withOfferCount = false): JsonResponse
    {
        $limit = (int) ($data['limit'] ?? 20);
        $query->with(['actor', 'category', 'city'])->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'needs.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }
        if ($withOfferCount) {
            $query->withCount(['offers as pending_offers' => fn ($q) => $q->where('status', 'pending')]);
        }

        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($n) => $this->needData($n, $withOfferCount) + ($withOfferCount ? ['pending_offers' => $n->pending_offers] : [])),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode($last->created_at, $last->id) : null,
        ]);
    }

    private function needData(Need $n, bool $owner): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->kind,
            'status' => $n->status,
            'title' => $n->title,
            'description' => $n->description,
            'category' => $n->category ? ['id' => $n->category->id, 'slug' => $n->category->slug, 'name' => $n->category->name, 'name_ps' => $n->category->name_ps, 'name_en' => $n->category->name_en] : null,
            'city' => $n->city ? ['id' => $n->city->id, 'name' => $n->city->name, 'name_ps' => $n->city->name_ps, 'name_en' => $n->city->name_en] : null,
            'actor' => ['id' => $n->actor->id, 'display_name' => $n->actor->display_name, 'type' => $n->actor->type],
            'is_mine' => $owner,
            'expires_at' => $n->expires_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    private function offerData(Offer $o): array
    {
        return [
            'id' => $o->id,
            'need_id' => $o->need_id,
            'status' => $o->status,
            'amount' => (string) $o->amount,
            'currency' => $o->currency,
            'unit' => $o->price_unit,
            'days' => $o->days,
            'message' => $o->message,
            'provider' => ['id' => $o->provider->id, 'display_name' => $o->provider->display_name, 'type' => $o->provider->type, 'contact_phone' => $o->provider->contact_phone_e164, 'whatsapp' => $o->provider->whatsapp_e164],
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    /** Optional acting actor on a public route: only when the header is present and the user really is a member. */
    private function maybeActor(Request $request): ?Actor
    {
        $id = $request->header('X-Actor-Id');
        $actor = $id && Str::isUuid($id) ? Actor::find($id) : null;

        return $actor && $actor->membershipOf($request->user('sanctum')) ? $actor : null;
    }
}
