<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public profile of a person or business. Contact numbers are public on purpose: call and WhatsApp are the main buttons. */
class ActorResource extends JsonResource
{
    /** A single item is returned flat; lists are wrapped in `data`. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'verified' => $this->verified_at !== null,
            'status' => $this->status,
            'contact_phone' => $this->contact_phone_e164,
            'whatsapp' => $this->whatsapp_e164,
            'category' => $this->whenLoaded('category', fn () => $this->category ? new CategoryResource($this->category) : null),
            'city' => $this->whenLoaded('city', fn () => $this->city ? new CityResource($this->city) : null),
            'stats' => [
                'completed_count' => $this->stats->completed_count ?? 0,
                'rating_count' => $this->stats->rating_count ?? 0,
                'rating_avg' => isset($this->stats->rating_avg) ? (float) $this->stats->rating_avg : null,
            ],
            'service_areas' => $this->whenLoaded('serviceAreas', fn () => CityResource::collection($this->serviceAreas)),
            'services' => $this->whenLoaded('services', fn () => ServiceResource::collection($this->services)),
            'avatar_url' => $this->whenLoaded('media', fn () => $this->avatarUrl()),
            'cover_url' => $this->whenLoaded('media', fn () => $this->coverUrl()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
