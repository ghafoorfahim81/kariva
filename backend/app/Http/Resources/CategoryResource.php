<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Names come in all three languages; the client picks one and falls back to Dari (`name`). */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'name_ps' => $this->name_ps,
            'name_en' => $this->name_en,
            'icon' => $this->icon,
            'children' => $this->whenLoaded('children', fn () => CategoryResource::collection($this->children)),
        ];
    }
}
