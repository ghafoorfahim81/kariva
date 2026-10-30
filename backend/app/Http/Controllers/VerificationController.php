<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\VerificationRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Identity verification of the acting actor (person or business).
 * "Verified" means an admin saw the document; it does not vouch for the quality of the work.
 * The document number is never stored; only the photos are kept, privately, for a limited time.
 */
class VerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('view', $actor);

        $latest = VerificationRequest::where('actor_id', $actor->id)->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json([
            'verified' => $actor->verified_at !== null,
            'request' => $latest ? $this->data($latest) : null,
        ]);
    }

    /** Opens a request; the photos are then uploaded to POST /media (model_type verification_request, collection document). */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('actor');
        Gate::authorize('requestVerification', $actor);
        if ($actor->verified_at !== null) {
            throw new ApiException('verification.already_verified', 409);
        }

        try {
            $row = DB::transaction(fn () => VerificationRequest::create(['actor_id' => $actor->id, 'kind' => $actor->type, 'requested_by' => $request->user()->id])->refresh());
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'one_pending_verification')) {
                throw new ApiException('verification.already_pending', 409);
            }
            throw $e;
        }

        return response()->json($this->data($row), 201);
    }

    private function data(VerificationRequest $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'status' => $r->status,
            'note' => $r->status === 'pending' ? null : $r->admin_note,   // the admin's explanation, shown after the decision
            'documents' => DB::table('media')->where(['model_type' => 'verification_request', 'model_id' => $r->id])->count(),
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}
