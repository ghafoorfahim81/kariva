<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Actor;
use App\Models\Message;
use App\Models\Need;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Reports (abuse) and favorites. Both are per user, not per actor. */
class TrustController extends Controller
{
    private const FAVORITE_TYPES = ['actor' => Actor::class, 'service' => Service::class, 'need' => Need::class];

    private const REPORT_TYPES = ['actor' => Actor::class, 'service' => Service::class, 'review' => Review::class, 'message' => Message::class];

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::REPORT_TYPES))],
            'id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:2', 'max:100'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = self::REPORT_TYPES[$data['type']];
        abort_unless($model::whereKey($data['id'])->exists(), 404);

        try {
            $id = (string) Str::uuid();
            DB::transaction(fn () => DB::table('reports')->insert([
                'id' => $id, 'reporter_user_id' => $request->user()->id, 'reportable_type' => $data['type'], 'reportable_id' => $data['id'],
                'reason' => $data['reason'], 'details' => $data['details'] ?? null,
            ]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'one_open_report_per_user')) {
                throw new ApiException('report.already_open', 409);   // one open report per user and subject
            }
            throw $e;
        }

        return response()->json(['id' => $id, 'status' => 'open'], 201);
    }

    public function favorites(Request $request): JsonResponse
    {
        $rows = DB::table('favorites')->where('user_id', $request->user()->id)->orderByDesc('created_at')->get(['favoritable_type as type', 'favoritable_id as id', 'created_at']);

        return response()->json(['data' => $rows]);
    }

    public function addFavorite(Request $request, string $type, string $id): JsonResponse
    {
        $model = self::FAVORITE_TYPES[$type] ?? abort(404);
        abort_unless(Str::isUuid($id) && $model::whereKey($id)->exists(), 404);

        DB::table('favorites')->insertOrIgnore(['user_id' => $request->user()->id, 'favoritable_type' => $type, 'favoritable_id' => $id, 'created_at' => now()]);

        return response()->json(['type' => $type, 'id' => $id], 201);
    }

    public function removeFavorite(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless(isset(self::FAVORITE_TYPES[$type]) && Str::isUuid($id), 404);
        DB::table('favorites')->where(['user_id' => $request->user()->id, 'favoritable_type' => $type, 'favoritable_id' => $id])->delete();

        return response()->json(null, 204);
    }
}
