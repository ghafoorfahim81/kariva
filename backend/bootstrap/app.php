<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\ResolveActor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-based Sanctum for the Vue SPA; tokens are added when the mobile app starts.
        $middleware->statefulApi();
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->api(append: [SecurityHeaders::class]);
        $middleware->alias(['actor' => ResolveActor::class, 'admin' => EnsureAdmin::class, 'active' => EnsureUserActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule failures: a stable `code` the client translates, never a sentence.
        $exceptions->render(function (ApiException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->errorCode] + $e->context, $e->status);
        });
        // Safety net: if a rule that the database enforces slips past validation, answer 422, not 500.
        $exceptions->render(function (QueryException $e) {
            $state = $e->errorInfo[0] ?? null;
            if (in_array($state, ['23505', '23514', '23503', '23502'], true)) {
                return response()->json(['code' => 'rule_violation', 'message' => 'rule_violation', 'sqlstate' => $state], 422);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
