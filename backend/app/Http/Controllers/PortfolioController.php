<?php

namespace App\Http\Controllers;

use App\Models\Actor;
use App\Models\PortfolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Work samples: a title, a short text and up to 10 photos (uploaded through /media, collection "images"). */
class PortfolioController extends Controller
{
    public function index(Actor $actor): JsonResponse
    {
        abort_unless($actor->isActive(), 404);

        $items = PortfolioItem::where('actor_id', $actor->id)->with('media')->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json(['data' => $items->map(fn ($i) => $this->data($i))]);
    }

    public function store(Request $request, Actor $actor): JsonResponse
    {
        $this->authorize('update', $actor);
        $data = $this->validated($request, $actor);

        $item = $actor->hasMany(PortfolioItem::class)->create($data + ['sort_order' => (int) PortfolioItem::where('actor_id', $actor->id)->max('sort_order') + 1]);

        return response()->json($this->data($item->refresh()->load('media')), 201);
    }

    public function update(Request $request, PortfolioItem $item): JsonResponse
    {
        $this->authorize('update', $item->actor);
        $item->update($this->validated($request, $item->actor, partial: true));

        return response()->json($this->data($item->refresh()->load('media')));
    }

    public function destroy(PortfolioItem $item): JsonResponse
    {
        $this->authorize('update', $item->actor);

        DB::transaction(function () use ($item) {
            foreach (DB::table('media')->where(['model_type' => 'portfolio_item', 'model_id' => $item->id])->get() as $m) {
                Storage::disk($m->disk)->delete($m->path);
            }
            DB::table('media')->where(['model_type' => 'portfolio_item', 'model_id' => $item->id])->delete();
            $item->delete();
        });

        return response()->json(null, 204);
    }

    private function validated(Request $request, Actor $actor, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // only a service of the same actor (the database enforces it as well)
            'service_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('services', 'id')->where('actor_id', $actor->id)],
        ]);
    }

    private function data(PortfolioItem $i): array
    {
        return [
            'id' => $i->id,
            'actor_id' => $i->actor_id,
            'service_id' => $i->service_id,
            'title' => $i->title,
            'description' => $i->description,
            'images' => $i->media->map(fn ($m) => Storage::disk($m->disk)->url($m->path))->values(),
        ];
    }
}
