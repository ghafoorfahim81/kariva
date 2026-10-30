<?php

namespace App\Http\Controllers;

use App\Actions\Media\StoreMedia;
use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Media;
use App\Models\PortfolioItem;
use App\Models\Service;
use App\Models\VerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    public function store(Request $request, StoreMedia $store): JsonResponse
    {
        $data = $request->validate([
            'model_type' => ['required', Rule::in(['actor', 'service', 'portfolio_item', 'verification_request'])],
            'model_id' => ['required', 'uuid'],
            'collection' => ['required', 'string', 'max:30'],
            'file' => ['required', 'file'],
        ]);

        $media = $store($request->user(), $data['model_type'], $data['model_id'], $data['collection'], $request->file('file'));

        // The scan may already have run (and removed an infected file). Public only once it is clean.
        $status = DB::table('media')->where('id', $media->id)->value('scan_status');
        if ($status === null) {
            throw new ApiException('media.infected', 422);
        }

        return response()->json([
            'id' => $media->id,
            'collection' => $media->collection,
            'scan_status' => $status,
            // private files (identity documents) never get a URL
            'url' => $status === 'clean' && $media->visibility === 'public' ? Storage::disk($media->disk)->url($media->path) : null,
        ], 201);
    }

    /** Removes the avatar or cover of a page or profile without the client needing the media id. */
    public function destroySlot(Actor $actor, string $collection): JsonResponse
    {
        abort_unless(in_array($collection, ['avatar', 'cover'], true), 404);
        $this->authorize('update', $actor);

        DB::transaction(function () use ($actor, $collection) {
            $rows = DB::table('media')->where(['model_type' => 'actor', 'model_id' => $actor->id, 'collection' => $collection]);
            foreach ((clone $rows)->get() as $m) {
                Storage::disk($m->disk)->delete($m->path);
            }
            $rows->delete();
        });

        return response()->json(null, 204);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $owner = match ($media->model_type) {
            'actor' => Actor::find($media->model_id),
            'service' => Service::find($media->model_id)?->actor,
            'portfolio_item' => PortfolioItem::find($media->model_id)?->actor,
            'verification_request' => VerificationRequest::where('status', 'pending')->find($media->model_id)?->actor,
            default => null,
        };
        abort_unless($owner, 404);
        $this->authorize($media->model_type === 'verification_request' ? 'requestVerification' : 'update', $owner);

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return response()->json(null, 204);
    }
}
