<?php

namespace App\Http\Controllers;

use App\Actions\Search\SearchActors;
use App\Http\Resources\ActorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function actors(Request $request, SearchActors $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'uuid'],
            'type' => ['nullable', 'in:person,business'],
            'cursor' => ['nullable', 'string', 'max:300'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SearchActors::MAX_LIMIT],
        ]);

        $result = $search($data['q'] ?? null, $data['category'] ?? null, $data['city'] ?? null, $data['cursor'] ?? null, (int) ($data['limit'] ?? SearchActors::DEFAULT_LIMIT), $data['type'] ?? null);

        return response()->json([
            'data' => ActorResource::collection($result['items']),
            'next_cursor' => $result['next_cursor'],
        ]);
    }
}
