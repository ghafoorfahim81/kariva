<?php

namespace App\Http\Controllers;

use App\Actions\Actors\CreateBusinessActor;
use App\Actions\Actors\DeleteBusinessActor;
use App\Actions\Search\RefreshSearchText;
use App\Http\Requests\ActorRequest;
use App\Http\Resources\ActorResource;
use App\Models\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActorController extends Controller
{
    /** Public profile. Pages that are suspended or deleted are not shown to anyone. */
    public function show(Actor $actor): ActorResource
    {
        abort_unless($actor->isActive(), 404);

        return new ActorResource($actor->load([
            'category', 'city', 'stats', 'serviceAreas',
            'services' => fn ($q) => $q->where('status', 'active')->with('category')->orderBy('created_at'),
        ]));
    }

    public function store(ActorRequest $request, CreateBusinessActor $create): JsonResponse
    {
        $actor = $create($request->user(), $request->attributesForModel());

        return (new ActorResource($actor->load(['category', 'city', 'stats'])))->response()->setStatusCode(201);
    }

    public function update(ActorRequest $request, Actor $actor, RefreshSearchText $search): ActorResource
    {
        $this->authorize('update', $actor);

        DB::transaction(function () use ($request, $actor, $search) {
            $actor->update($request->attributesForModel());
            $search->actor($actor->id);
        });

        return new ActorResource($actor->refresh()->load(['category', 'city', 'stats', 'serviceAreas']));
    }

    /** Cities where the actor works. Replaces the whole list. */
    public function syncServiceAreas(Request $request, Actor $actor): ActorResource
    {
        $this->authorize('update', $actor);
        $data = $request->validate(['city_ids' => ['present', 'array', 'max:40'], 'city_ids.*' => ['uuid', 'distinct', 'exists:cities,id']]);

        $actor->serviceAreas()->sync($data['city_ids']);

        return new ActorResource($actor->load(['category', 'city', 'stats', 'serviceAreas']));
    }

    public function requestDeletion(Actor $actor, DeleteBusinessActor $delete, Request $request): JsonResponse
    {
        $this->authorize('requestDeletion', $actor);
        $delete->request($request->user(), $actor);

        return response()->json(null, 204);
    }

    public function cancelDeletion(Actor $actor, DeleteBusinessActor $delete, Request $request): JsonResponse
    {
        $this->authorize('requestDeletion', $actor);
        $delete->cancel($request->user(), $actor);

        return response()->json(null, 204);
    }

    public function confirmDeletion(Actor $actor, DeleteBusinessActor $delete, Request $request): JsonResponse
    {
        $this->authorize('confirmDeletion', $actor);
        $delete->confirm($request->user(), $actor);

        return response()->json(null, 204);
    }
}
