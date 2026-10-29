<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    /** A single item is returned flat; lists are wrapped in `data`. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'category' => $this->whenLoaded('category', fn () => new CategoryResource($this->category)),
            'title' => $this->title,
            'description' => $this->description,
            'delivery_mode' => $this->delivery_mode,
            'pricing' => [
                'type' => $this->pricing_type,
                'amount' => $this->price_amount !== null ? (string) $this->price_amount : null,
                'currency' => $this->currency,
                'unit' => $this->price_unit,
            ],
            'status' => $this->status,
        ];
    }
}
