<?php

namespace App\Http\Controllers;

use App\Support\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** In-app notifications of the signed-in user (they are per user, not per actor). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:200'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50'], 'unread' => ['nullable', 'boolean']]);
        $limit = (int) ($data['limit'] ?? 20);
        $userId = $request->user()->id;

        $query = DB::table('notifications')->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id')
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'));
        if (! empty($data['cursor'])) {
            [$at, $id] = Cursor::decode($data['cursor'], 'notifications.bad_cursor');
            $query->where(fn ($q) => $q->where('created_at', '<', $at)->orWhere(fn ($q) => $q->where('created_at', $at)->where('id', '<', $id)));
        }
        $rows = $query->limit($limit + 1)->get();
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => json_decode($n->data, true),
                'read' => $n->read_at !== null,
                'created_at' => date(DATE_ATOM, strtotime($n->created_at)),
            ]),
            'unread_count' => DB::table('notifications')->where('user_id', $userId)->whereNull('read_at')->count(),
            'next_cursor' => $rows->count() > $limit && $last ? Cursor::encode(new \DateTimeImmutable($last->created_at), $last->id) : null,
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        abort_unless(Str::isUuid($notification), 404);
        $updated = DB::table('notifications')->where(['id' => $notification, 'user_id' => $request->user()->id])->update(['read_at' => DB::raw('COALESCE(read_at, now())')]);
        abort_unless($updated, 404);

        return response()->json(null, 204);
    }

    public function readAll(Request $request): JsonResponse
    {
        DB::table('notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(null, 204);
    }
}
