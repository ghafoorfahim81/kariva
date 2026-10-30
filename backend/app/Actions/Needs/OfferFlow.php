<?php

namespace App\Actions\Needs;

use App\Actions\Orders\OrderFlow;
use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Order;
use App\Support\Notifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Needs and offers. A service need collects offers; accepting one creates an order (origin "offer") and closes the need.
 * A job need (an opportunity to hire) never gets offers or orders: people just talk.
 */
class OfferFlow
{
    public function __construct(private OrderFlow $orders) {}

    public function openNeed(Actor $by, array $data): Need
    {
        if (! $by->isActive()) {
            throw new ApiException('order.actor_unavailable', 422);
        }

        return Need::create($data + [
            'actor_id' => $by->id,
            'expires_at' => now()->addDays(config('kariva.needs_lifetime_days')),
        ])->refresh();
    }

    /** The owner closes the need; pending offers are rejected. */
    public function closeNeed(Need $need, Actor $by): Need
    {
        return DB::transaction(function () use ($need, $by) {
            $n = Need::whereKey($need->id)->lockForUpdate()->firstOrFail();
            if ($n->actor_id !== $by->id) {
                throw new ApiException('need.wrong_side', 403);
            }
            if ($n->status !== 'open') {
                throw new ApiException('need.not_open', 409);
            }
            $this->rejectPending($n->id, null);
            $n->update(['status' => 'closed']);

            return $n->refresh();
        });
    }

    public function offer(Need $need, Actor $provider, array $data): Offer
    {
        if (! $need->isOpen()) {
            throw new ApiException('need.not_open', 409);
        }
        if ($need->kind !== 'service') {
            throw new ApiException('need.offers_not_allowed', 422);   // a job opportunity only starts a conversation
        }
        if ($need->actor_id === $provider->id) {
            throw new ApiException('offer.own_need', 422);
        }
        if (! $provider->isActive()) {
            throw new ApiException('order.actor_unavailable', 422);
        }

        try {
            $offer = DB::transaction(fn () => Offer::create($data + ['need_id' => $need->id, 'provider_actor_id' => $provider->id])->refresh());
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'offers_one_per_provider')) {
                throw new ApiException('offer.already_made', 409);
            }
            throw $e;
        }

        Notifier::actor($need->actor_id, 'offer.received', "offer:{$offer->id}:received", ['need_id' => $need->id, 'offer_id' => $offer->id, 'title' => $need->title, 'by' => $provider->display_name]);

        return $offer;
    }

    public function withdraw(Offer $offer, Actor $by): Offer
    {
        $o = Offer::whereKey($offer->id)->firstOrFail();
        if ($o->provider_actor_id !== $by->id) {
            throw new ApiException('offer.wrong_side', 403);
        }
        if ($o->status !== 'pending') {
            throw new ApiException('offer.not_pending', 409);
        }
        $o->update(['status' => 'withdrawn']);

        return $o;
    }

    public function reject(Offer $offer, Actor $by): Offer
    {
        $o = Offer::with('need')->whereKey($offer->id)->firstOrFail();
        if ($o->need->actor_id !== $by->id) {
            throw new ApiException('offer.wrong_side', 403);
        }
        if ($o->status !== 'pending') {
            throw new ApiException('offer.not_pending', 409);
        }
        $o->update(['status' => 'rejected']);
        Notifier::actor($o->provider_actor_id, 'offer.rejected', "offer:{$o->id}:rejected", ['need_id' => $o->need_id, 'title' => $o->need->title]);

        return $o;
    }

    /** The need owner picks an offer: an order starts, the other offers are turned down, the need closes. */
    public function accept(Offer $offer, Actor $by): Order
    {
        return DB::transaction(function () use ($offer, $by) {
            $need = DB::table('needs')->where('id', $offer->need_id)->lockForUpdate()->first();
            $row = DB::table('offers')->where('id', $offer->id)->lockForUpdate()->first();

            if ($need->actor_id !== $by->id) {
                throw new ApiException('offer.wrong_side', 403);
            }
            if ($need->status !== 'open' || ($need->expires_at !== null && now()->gt($need->expires_at))) {
                throw new ApiException('need.not_open', 409);
            }
            if ($row->status !== 'pending') {
                throw new ApiException('offer.not_pending', 409);
            }

            $provider = Actor::findOrFail($row->provider_actor_id);
            if (! $provider->isActive() || ! $by->isActive()) {
                throw new ApiException('order.actor_unavailable', 422);
            }

            $this->rejectPending($need->id, $row->id);
            DB::table('offers')->where('id', $row->id)->update(['status' => 'accepted', 'updated_at' => now()]);
            DB::table('needs')->where('id', $need->id)->update(['status' => 'closed', 'updated_at' => now()]);

            return $this->orders->startFromOffer($by, $provider, $need, $row);
        });
    }

    /** Turns down every pending offer of the need except the one that was chosen. */
    private function rejectPending(string $needId, ?string $exceptOfferId): void
    {
        $query = DB::table('offers')->where(['need_id' => $needId, 'status' => 'pending'])
            ->when($exceptOfferId, fn ($q) => $q->where('id', '!=', $exceptOfferId));
        $rejected = (clone $query)->get(['id', 'provider_actor_id']);
        $query->update(['status' => 'rejected', 'updated_at' => now()]);

        foreach ($rejected as $o) {
            Notifier::actor($o->provider_actor_id, 'offer.rejected', "offer:{$o->id}:rejected", ['need_id' => $needId]);
        }
    }
}
