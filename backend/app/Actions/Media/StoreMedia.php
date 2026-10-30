<?php

namespace App\Actions\Media;

use App\Exceptions\ApiException;
use App\Jobs\ScanMedia;
use App\Models\Actor;
use App\Models\Media;
use App\Models\PortfolioItem;
use App\Models\Service;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreMedia
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __invoke(User $user, string $modelType, string $modelId, string $collection, UploadedFile $file): Media
    {
        $rules = config("kariva.media.collections.$modelType.$collection")
            ?? throw new ApiException('media.collection_unsupported', 422);

        $owner = $this->owningActor($modelType, $modelId);
        // identity documents: only an owner of the actor, and only while the request is waiting for review
        $private = ! empty($rules['private']);
        Gate::forUser($user)->authorize($modelType === 'verification_request' ? 'requestVerification' : 'update', $owner);
        if ($modelType === 'verification_request' && VerificationRequest::findOrFail($modelId)->status !== 'pending') {
            throw new ApiException('verification.not_pending', 409);
        }

        $mime = $file->getMimeType();   // detected from the content
        if (! in_array($mime, config('kariva.media.image_types'), true)) {
            throw new ApiException('media.invalid_type', 422);
        }
        if ($file->getSize() > $rules['max_kb'] * 1024) {
            throw new ApiException('media.too_large', 422, ['max_kb' => $rules['max_kb']]);
        }

        $disk = config($private ? 'kariva.media.private_disk' : 'kariva.media.public_disk');   // documents never go to the public disk
        $path = "$modelType/$modelId/".Str::uuid().'.'.self::EXTENSIONS[$mime];

        return DB::transaction(function () use ($user, $modelType, $modelId, $collection, $file, $rules, $disk, $path, $mime, $private) {
            // one upload at a time per slot, so the count/replace below cannot race
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["media:$modelType:$modelId:$collection"]);
            $existing = DB::table('media')->where(['model_type' => $modelType, 'model_id' => $modelId, 'collection' => $collection]);

            if ($rules['max_count'] === 1) {
                // a single-slot collection (avatar, cover) is replaced
                foreach ((clone $existing)->get() as $old) {
                    Storage::disk($old->disk)->delete($old->path);
                }
                $existing->delete();
            } elseif ($existing->count() >= $rules['max_count']) {
                throw new ApiException('media.limit_reached', 422, ['max_count' => $rules['max_count']]);
            }

            Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

            $media = Media::create([
                'model_type' => $modelType,
                'model_id' => $modelId,
                'collection' => $collection,
                'visibility' => $private ? 'private' : 'public',
                'disk' => $disk,
                'path' => $path,
                'mime' => $mime,
                'size_bytes' => $file->getSize(),
                'sort_order' => (int) DB::table('media')->where(['model_type' => $modelType, 'model_id' => $modelId, 'collection' => $collection])->max('sort_order') + 1,
                'created_by' => $user->id,
            ]);

            ScanMedia::dispatch($media->id)->afterCommit();

            return $media;
        });
    }

    private function owningActor(string $modelType, string $modelId): Actor
    {
        return match ($modelType) {
            'actor' => Actor::findOrFail($modelId),
            'service' => Service::with('actor')->findOrFail($modelId)->actor,
            'portfolio_item' => PortfolioItem::with('actor')->findOrFail($modelId)->actor,
            'verification_request' => VerificationRequest::with('actor')->findOrFail($modelId)->actor,
            default => throw new ApiException('media.collection_unsupported', 422),
        };
    }
}
