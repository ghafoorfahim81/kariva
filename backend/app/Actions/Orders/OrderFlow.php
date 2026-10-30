<?php

namespace App\Actions\Orders;

use App\Actions\Auth\SendOtp;
use App\Actions\Auth\VerifyOtp;
use App\Actions\Stats\RefreshActorStats;
use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one status machine of an order (spec section 9). Every method locks the order row first, so two people
 * acting at the same moment (for example accept and cancel) cannot leave it in an impossible state.
 * Each change writes one `order_events` row and notifies the other side.
 * The database enforces the same transitions; the checks here give the client a clear error code.
 */
class OrderFlow
{
    public function __construct(private RefreshActorStats $stats) {}

    /** A customer asks a provider: for one of its services, or directly. */
    public function create(Actor $customer, array $input): Order
    {
        if (! empty($input['service_id'])) {
            $service = Service::with('actor')->find($input['service_id']);
            if (! $service || $service->status !== 'active') {
                throw new ApiException('order.service_unavailable', 422);
            }
            $provider = $service->actor;
            $origin = 'service';
            $title = $service->title;
        } else {
            $provider = Actor::find($input['provider_actor_id']) ?? throw new ApiException('order.provider_not_found', 404);
            $service = null;
            $origin = 'direct';
            $title = trim($input['title']);
        }

        if ($provider->id === $customer->id) {
            throw new ApiException('order.cannot_order_from_self', 422);
        }
        if (! $provider->isActive() || ! $customer->isActive()) {
            throw new ApiException('order.actor_unavailable', 422);   // suspension stops new orders only
        }

        return DB::transaction(function () use ($customer, $provider, $service, $origin, $title, $input) {
            $order = Order::create([
                'origin' => $origin,
                'customer_actor_id' => $customer->id,
                'provider_actor_id' => $provider->id,
                'service_id' => $service?->id,
                'title_snapshot' => $title,
                'description' => $input['description'] ?? null,
                'status' => 'requested',
            ]);

            $this->openConversation($order);

            $this->event($order, 'created', $customer, null, 'requested');
            Notifier::actor($provider->id, 'order.requested', "order:{$order->id}:requested", $this->payload($order, $customer));

            return $order->refresh();
        });
    }

    /**
     * "Invited past job": a provider records work done before, so a new profile is not empty. The customer is found by
     * phone number (an account is prepared if there is none) and gets a code; the order counts only after the code is entered.
     */
    public function createInvited(Actor $provider, string $phone, array $input, ?string $ip): Order
    {
        if (! $provider->isActive()) {
            throw new ApiException('order.actor_unavailable', 422);
        }

        $order = DB::transaction(function () use ($provider, $phone, $input) {
            $user = User::withTrashed()->where('phone_e164', $phone)->first();
            if ($user?->trashed() || $user?->status === 'suspended') {
                throw new ApiException('order.actor_unavailable', 422);
            }
            $customer = $user ? Actor::where('personal_user_id', $user->id)->firstOrFail() : $this->prepareCustomer($phone);

            if ($customer->id === $provider->id || DB::table('actor_members')->where(['actor_id' => $provider->id, 'user_id' => $customer->personal_user_id])->exists()) {
                throw new ApiException('order.cannot_order_from_self', 422);
            }

            try {
                $order = DB::transaction(fn () => Order::create([
                    'origin' => 'invited',
                    'customer_actor_id' => $customer->id,
                    'provider_actor_id' => $provider->id,
                    'title_snapshot' => trim($input['title']),
                    'description' => $input['description'] ?? null,
                    'status' => 'invited',
                ]));
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'limit reached')) {
                    throw new ApiException('invited.limit_reached', 422, ['max_per_month' => config('kariva.invited_orders_per_month')]);
                }
                throw $e;
            }
            $this->openConversation($order);
            $this->event($order, 'invited', $provider, null, 'invited');

            return $order;
        });

        // The order exists either way; if the code could not be sent (rate limit) the provider can ask again.
        try {
            $this->sendCode($phone, $ip);
            $sent = true;
        } catch (ApiException) {
            $sent = false;
        }

        return $order->refresh()->setAttribute('code_sent', $sent);
    }

    /** Sends the confirmation code to the customer again. */
    public function resendInvitedCode(Order $order, Actor $by, ?string $ip): void
    {
        $o = $this->invitedOrder($order, $by, 'provider');
        $this->sendCode($this->customerPhone($o), $ip);
    }

    /** The code (read from the customer's phone) turns the past job into a completed order. Either party may enter it. */
    public function confirmInvited(Order $order, Actor $by, string $code): Order
    {
        $o = $this->invitedOrder($order, $by, null);
        app(VerifyOtp::class)($this->customerPhone($o), 'invited_job', $code);

        return DB::transaction(function () use ($o, $by) {
            $locked = Order::whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'invited') {
                throw new ApiException('order.invalid_state', 409, ['status' => $locked->status]);
            }
            $locked->update(['status' => 'completed', 'completed_at' => now()]);
            $this->event($locked, 'confirmed_by_code', $by, 'invited', 'completed');
            ($this->stats)($locked->provider_actor_id);
            Notifier::actor($locked->provider_actor_id, 'order.completed', "order:{$locked->id}:completed", $this->payload($locked, $by));
            Notifier::actor($locked->customer_actor_id, 'order.review_invite', "order:{$locked->id}:review_invite", $this->payload($locked, $by));

            return $locked->refresh();
        });
    }

    private function invitedOrder(Order $order, Actor $by, ?string $side): Order
    {
        $o = Order::findOrFail($order->id);
        $isProvider = $o->provider_actor_id === $by->id;
        $isCustomer = $o->customer_actor_id === $by->id;
        if ($o->origin !== 'invited' || (! $isProvider && ! $isCustomer)) {
            throw new ApiException('order.wrong_side', 403);
        }
        if ($side === 'provider' && ! $isProvider) {
            throw new ApiException('order.wrong_side', 403);
        }
        if ($o->status !== 'invited') {
            throw new ApiException('order.invalid_state', 409, ['status' => $o->status]);
        }

        return $o;
    }

    private function customerPhone(Order $o): string
    {
        return DB::table('users')->join('actors', 'actors.personal_user_id', '=', 'users.id')->where('actors.id', $o->customer_actor_id)->value('users.phone_e164');
    }

    private function sendCode(string $phone, ?string $ip): void
    {
        app(SendOtp::class)($phone, 'invited_job', $ip);
    }

    /** A customer who has never used Kariva: a user in status "invited" with its personal actor. They become active on first login. */
    private function prepareCustomer(string $phone): Actor
    {
        $user = User::create(['phone_e164' => $phone, 'status' => 'invited']);
        $actor = Actor::create(['type' => 'person', 'personal_user_id' => $user->id, 'display_name' => 'کاربر '.substr($phone, -4), 'search_text' => '']);
        DB::table('actor_members')->insert(['actor_id' => $actor->id, 'user_id' => $user->id, 'role' => 'owner', 'is_primary' => true]);

        return $actor;
    }

    /**
     * Accepting an offer on a service need starts the order straight in "accepted": the offer already carries the price.
     * Called inside the transaction of OfferFlow::accept, which has locked the need and the offer.
     */
    public function startFromOffer(Actor $customer, Actor $provider, object $need, object $offer): Order
    {
        $order = Order::create([
            'origin' => 'offer',
            'customer_actor_id' => $customer->id,
            'provider_actor_id' => $provider->id,
            'need_id' => $need->id,
            'offer_id' => $offer->id,
            'title_snapshot' => $need->title,
            'description' => $need->description,
            'status' => 'accepted',
            'agreed_amount' => $offer->amount,
            'currency' => $offer->currency,
            'price_unit' => $offer->price_unit,
        ]);
        $this->openConversation($order);
        $this->event($order, 'created_from_offer', $customer, null, 'accepted', ['offer_id' => $offer->id, 'need_id' => $need->id]);
        Notifier::actor($provider->id, 'offer.accepted', "offer:{$offer->id}:accepted", $this->payload($order, $customer));

        return $order->refresh();
    }

    /** The provider names a price. A newer quote replaces the pending one. */
    public function quote(Order $order, Actor $by, array $quote): Order
    {
        return $this->run($order, $by, 'provider', ['requested', 'quoted'], function (Order $o) use ($quote, $by) {
            DB::table('quotes')->where(['order_id' => $o->id, 'status' => 'pending'])->update(['status' => 'superseded', 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('quotes')->insert([
                'id' => $id, 'order_id' => $o->id, 'amount' => $quote['amount'], 'currency' => $quote['currency'],
                'price_unit' => $quote['price_unit'], 'days' => $quote['days'] ?? null, 'note' => $quote['note'] ?? null,
                'created_at' => self::stamp(),
            ]);
            $from = $o->status;
            $o->update(['status' => 'quoted']);
            $this->event($o, 'quoted', $by, $from, 'quoted', ['quote_id' => $id, 'amount' => $quote['amount'], 'currency' => $quote['currency']]);
            Notifier::actor($o->customer_actor_id, 'order.quoted', "order:{$o->id}:quote:$id", $this->payload($o, $by));
        });
    }

    /** The customer accepts the pending price: it becomes the agreed price and is locked. */
    public function accept(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'customer', ['quoted'], function (Order $o) use ($by) {
            $quote = DB::table('quotes')->where(['order_id' => $o->id, 'status' => 'pending'])->lockForUpdate()->first()
                ?? throw new ApiException('order.no_pending_quote', 409);

            DB::table('quotes')->where('id', $quote->id)->update(['status' => 'accepted', 'updated_at' => now()]);
            $o->update(['status' => 'accepted', 'agreed_amount' => $quote->amount, 'currency' => $quote->currency, 'price_unit' => $quote->price_unit]);
            $this->event($o, 'accepted', $by, 'quoted', 'accepted', ['quote_id' => $quote->id]);
            Notifier::actor($o->provider_actor_id, 'order.accepted', "order:{$o->id}:accepted", $this->payload($o, $by));
        });
    }

    public function reject(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'provider', ['requested', 'quoted'], function (Order $o) use ($by) {
            $this->closePendingQuote($o, 'rejected');
            $from = $o->status;
            $o->update(['status' => 'rejected']);
            $this->event($o, 'rejected', $by, $from, 'rejected');
            Notifier::actor($o->customer_actor_id, 'order.rejected', "order:{$o->id}:rejected", $this->payload($o, $by));
        });
    }

    public function cancel(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'customer', ['requested', 'quoted'], function (Order $o) use ($by) {
            $this->closePendingQuote($o, 'superseded');
            $from = $o->status;
            $o->update(['status' => 'cancelled']);
            $this->event($o, 'cancelled', $by, $from, 'cancelled');
            Notifier::actor($o->provider_actor_id, 'order.cancelled', "order:{$o->id}:cancelled", $this->payload($o, $by));
        });
    }

    /** The provider says the work is finished; the customer now confirms. */
    public function done(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'provider', ['accepted'], function (Order $o) use ($by) {
            $o->update(['status' => 'provider_done']);
            $this->event($o, 'provider_done', $by, 'accepted', 'provider_done');
            Notifier::actor($o->customer_actor_id, 'order.provider_done', "order:{$o->id}:provider_done", $this->payload($o, $by));
        });
    }

    /** The customer confirms the work. Only now can a review be written. */
    public function confirm(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'customer', ['provider_done'], fn (Order $o) => $this->complete($o, $by, 'completed'));
    }

    /** "I have a problem". In the MVP this is only recorded and reviewed by hand. */
    public function dispute(Order $order, Actor $by): Order
    {
        return $this->run($order, $by, 'customer', ['provider_done'], function (Order $o) use ($by) {
            $o->update(['status' => 'disputed']);
            $this->event($o, 'disputed', $by, 'provider_done', 'disputed');
            Notifier::actor($o->provider_actor_id, 'order.disputed', "order:{$o->id}:disputed", $this->payload($o, $by));
        });
    }

    /** Called by the scheduler: no answer / no acceptance (expired), or no confirmation (completed by the system). */
    public function systemExpire(Order $order): void
    {
        $this->system($order, ['requested', 'quoted'], function (Order $o) {
            $this->closePendingQuote($o, 'superseded');
            $from = $o->status;
            $o->update(['status' => 'expired']);
            $this->event($o, 'expired', null, $from, 'expired');
            Notifier::actor($o->customer_actor_id, 'order.expired', "order:{$o->id}:expired", $this->payload($o, null));
            Notifier::actor($o->provider_actor_id, 'order.expired', "order:{$o->id}:expired", $this->payload($o, null));
        });
    }

    public function systemAutoComplete(Order $order): void
    {
        $this->system($order, ['provider_done'], fn (Order $o) => $this->complete($o, null, 'auto_completed'));
    }

    // ---------------------------------------------------------------- internals

    private function openConversation(Order $order): void
    {
        $conversation = (string) Str::uuid();
        DB::table('conversations')->insert(['id' => $conversation, 'order_id' => $order->id]);
        foreach ([$order->customer_actor_id, $order->provider_actor_id] as $actorId) {
            DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'actor_id' => $actorId]);
        }
    }

    private function complete(Order $o, ?Actor $by, string $event): void
    {
        $o->update(['status' => 'completed', 'completed_at' => now()]);
        $this->event($o, $event, $by, 'provider_done', 'completed');
        ($this->stats)($o->provider_actor_id);
        Notifier::actor($o->provider_actor_id, 'order.completed', "order:{$o->id}:completed", $this->payload($o, $by));
        Notifier::actor($o->customer_actor_id, 'order.review_invite', "order:{$o->id}:review_invite", $this->payload($o, $by));
    }

    /**
     * @param  'customer'|'provider'  $side  which party may do this
     * @param  list<string>  $from  statuses the order must be in
     */
    private function run(Order $order, Actor $by, string $side, array $from, callable $change): Order
    {
        return DB::transaction(function () use ($order, $by, $side, $from, $change) {
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $isSide = $side === 'customer' ? $o->customer_actor_id === $by->id : $o->provider_actor_id === $by->id;
            if (! $isSide) {
                throw new ApiException('order.wrong_side', 403);
            }
            if (! in_array($o->status, $from, true)) {
                throw new ApiException('order.invalid_state', 409, ['status' => $o->status]);
            }

            $change($o);

            return $o->refresh();
        });
    }

    private function system(Order $order, array $from, callable $change): void
    {
        DB::transaction(function () use ($order, $from, $change) {
            $o = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($o && in_array($o->status, $from, true)) {
                $change($o);
            }
        });
    }

    private function closePendingQuote(Order $o, string $as): void
    {
        DB::table('quotes')->where(['order_id' => $o->id, 'status' => 'pending'])->update(['status' => $as, 'updated_at' => now()]);
    }

    private function event(Order $o, string $event, ?Actor $by, ?string $from, ?string $to, array $meta = []): void
    {
        DB::table('order_events')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $o->id,
            'event' => $event,
            'by_actor_id' => $by?->id,
            'from_status' => $from,
            'to_status' => $to,
            'meta' => json_encode($meta),
            'at' => self::stamp(),
        ]);
    }

    private function payload(Order $o, ?Actor $by): array
    {
        return ['order_id' => $o->id, 'title' => $o->title_snapshot, 'by' => $by?->display_name];
    }

    /** Microsecond timestamp, so the steps of one order keep their order even inside one transaction. */
    private static function stamp(): string
    {
        return now()->format('Y-m-d H:i:s.uP');
    }
}
