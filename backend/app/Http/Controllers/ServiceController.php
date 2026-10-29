<?php

namespace App\Http\Controllers;

use App\Actions\Search\RefreshSearchText;
use App\Http\Requests\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Actor;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    /** Public: active services only. Members of the page see all of them (drafts, paused, archived) with ?all=1. */
    public function index(Request $request, Actor $actor): AnonymousResourceCollection
    {
        abort_unless($actor->isActive(), 404);
        $all = $request->boolean('all') && $request->user('sanctum')?->can('view', $actor);

        return ServiceResource::collection(
            $actor->services()->with(['category', 'media'])->when(! $all, fn ($q) => $q->where('status', 'active'))->orderBy('created_at')->get()
        );
    }

    public function show(Request $request, Service $service): ServiceResource
    {
        $service->load(['category', 'actor', 'media']);
        $visible = $service->status === 'active' && $service->actor->isActive();
        abort_unless($visible || $request->user('sanctum')?->can('view', $service->actor), 404);

        return new ServiceResource($service);
    }

    public function store(ServiceRequest $request, Actor $actor, RefreshSearchText $search): JsonResponse
    {
        $this->authorize('update', $actor);

        $service = DB::transaction(function () use ($request, $actor, $search) {
            $service = $actor->services()->create($request->attributesForModel());
            $search->service($service->id);
            $search->actor($actor->id);

            return $service;
        });

        return (new ServiceResource($service->refresh()->load('category')))->response()->setStatusCode(201);
    }

    public function update(ServiceRequest $request, Service $service, RefreshSearchText $search): ServiceResource
    {
        $this->authorize('update', $service->actor);

        DB::transaction(function () use ($request, $service, $search) {
            $service->update($request->attributesForModel());
            $search->service($service->id);
            $search->actor($service->actor_id);
        });

        return new ServiceResource($service->refresh()->load('category'));
    }

    /** Archiving hides the service from everyone; old orders keep their own title snapshot. */
    public function destroy(Service $service, RefreshSearchText $search): JsonResponse
    {
        $this->authorize('update', $service->actor);

        $service->update(['status' => 'archived']);
        $search->actor($service->actor_id);

        return response()->json(null, 204);
    }
}
