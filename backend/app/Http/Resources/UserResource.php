<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in user with every actor they can act as. The client sends the chosen actor id on each request. */
class UserResource extends JsonResource
{
    /** A single item is returned flat; lists are wrapped in `data`. */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone_e164,
            'email' => $this->email,
            'locale' => $this->locale,
            'status' => $this->status,
            'actors' => $this->actors->map(fn ($actor) => [
                'id' => $actor->id,
                'type' => $actor->type,
                'display_name' => $actor->display_name,
                'status' => $actor->status,
                'role' => $actor->pivot->role,
                'is_primary' => (bool) $actor->pivot->is_primary,
                'is_personal' => $actor->personal_user_id === $this->id,
            ])->values(),
        ];
    }
}
