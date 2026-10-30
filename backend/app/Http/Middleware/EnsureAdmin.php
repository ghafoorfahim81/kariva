<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel is for platform admins only. It is separate from the actor system: no X-Actor-Id, no membership. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_platform_admin || $user->status !== 'active') {
            throw new ApiException('admin.forbidden', 403);
        }

        return $next($request);
    }
}
