<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $mine = $request->attributes->get('actor');
        $quote = fn ($q) => ['id' => $q->id, 'amount' => (string) $q->amount, 'currency' => $q->currency, 'unit' => $q->price_unit, 'days' => $q->days, 'note' => $q->note, 'status' => $q->status, 'created_at' => $q->created_at?->toIso8601String()];

        return [
            'id' => $this->id,
            'origin' => $this->origin,
            'status' => $this->status,
            'my_side' => $mine ? ($mine->id === $this->customer_actor_id ? 'customer' : 'provider') : null,
            'title' => $this->title_snapshot,
            'description' => $this->description,
            'service_id' => $this->service_id,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'display_name' => $this->customer->display_name, 'type' => $this->customer->type]),
            'provider' => $this->whenLoaded('provider', fn () => ['id' => $this->provider->id, 'display_name' => $this->provider->display_name, 'type' => $this->provider->type, 'contact_phone' => $this->provider->contact_phone_e164, 'whatsapp' => $this->provider->whatsapp_e164]),
            'agreed' => $this->agreed_amount === null ? null : ['amount' => (string) $this->agreed_amount, 'currency' => $this->currency, 'unit' => $this->price_unit],
            'pending_quote' => $this->whenLoaded('pendingQuote', fn () => $this->pendingQuote ? $quote($this->pendingQuote) : null),
            'quotes' => $this->whenLoaded('quotes', fn () => $this->quotes->map($quote)->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'event' => $e->event, 'by_actor_id' => $e->by_actor_id, 'from' => $e->from_status, 'to' => $e->to_status, 'at' => $e->at?->toIso8601String(),
            ])->values()),
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'review' => $this->whenLoaded('review', fn () => $this->review ? ['id' => $this->review->id, 'stars' => $this->review->stars] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
