<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Actor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request that acts "as someone" carries X-Actor-Id. Whether the signed-in user may use that actor
 * is decided only by actor_members, never by anything the client says.
 */
class ResolveActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Actor-Id');
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new ApiException('actor.header_missing', 400);
        }

        $actor = Actor::find($id);
        $member = $actor?->membershipOf($request->user());
        if (! $actor || ! $member) {
            throw new ApiException('actor.forbidden', 403);
        }

        $request->attributes->set('actor', $actor);
        $request->attributes->set('member', $member);

        return $next($request);
    }
}
