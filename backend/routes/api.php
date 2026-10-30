<?php

use App\Http\Controllers\ActorController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NeedController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Served under /api/v1.
Route::get('/ping', fn () => response()->json([
    'app' => 'kariva',
    'database' => DB::selectOne('select 1 as ok')->ok === 1,
]));

Route::prefix('auth')->group(function () {
    Route::post('otp', [AuthController::class, 'sendOtp']);
    Route::post('verify', [AuthController::class, 'verify'])->middleware('throttle:20,1');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Public: reference data, search and profiles.
Route::get('categories', [CatalogController::class, 'categories']);
Route::get('provinces', [CatalogController::class, 'provinces']);
Route::get('cities', [CatalogController::class, 'cities']);
Route::get('search/actors', [SearchController::class, 'actors'])->middleware('throttle:60,1');
Route::get('actors/{actor}', [ActorController::class, 'show']);
Route::get('actors/{actor}/services', [ServiceController::class, 'index']);
Route::get('services/{service}', [ServiceController::class, 'show']);
Route::get('actors/{actor}/portfolio', [PortfolioController::class, 'index']);
Route::get('needs', [NeedController::class, 'index']);
Route::get('needs/{need}', [NeedController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    // Shows which actor the request acts as; the SPA calls it after switching actor.
    Route::get('context', fn (Request $r) => response()->json([
        'actor_id' => $r->attributes->get('actor')->id,
        'role' => $r->attributes->get('member')->role,
        'is_primary' => (bool) $r->attributes->get('member')->is_primary,
    ]))->middleware('actor');

    Route::post('actors', [ActorController::class, 'store']);
    Route::patch('actors/{actor}', [ActorController::class, 'update']);
    Route::put('actors/{actor}/service-areas', [ActorController::class, 'syncServiceAreas']);
    Route::post('actors/{actor}/deletion-request', [ActorController::class, 'requestDeletion']);
    Route::delete('actors/{actor}/deletion-request', [ActorController::class, 'cancelDeletion']);
    Route::post('actors/{actor}/deletion-confirm', [ActorController::class, 'confirmDeletion']);

    Route::get('actors/{actor}/members', [MemberController::class, 'index']);
    Route::patch('actors/{actor}/members/{user}', [MemberController::class, 'update']);
    Route::delete('actors/{actor}/members/{user}', [MemberController::class, 'destroy']);
    Route::get('actors/{actor}/invitations', [MemberController::class, 'invitations']);
    Route::post('actors/{actor}/invitations', [MemberController::class, 'invite']);
    Route::delete('actors/{actor}/invitations/{invitation}', [MemberController::class, 'cancelInvitation']);
    Route::get('invitations', [MemberController::class, 'mine']);
    Route::post('invitations/{invitation}/respond', [MemberController::class, 'respond']);

    Route::post('actors/{actor}/services', [ServiceController::class, 'store']);
    Route::patch('services/{service}', [ServiceController::class, 'update']);
    Route::delete('services/{service}', [ServiceController::class, 'destroy']);

    Route::post('actors/{actor}/portfolio', [PortfolioController::class, 'store']);
    Route::patch('portfolio/{item}', [PortfolioController::class, 'update']);
    Route::delete('portfolio/{item}', [PortfolioController::class, 'destroy']);

    // Needs and offers: acting as the actor in X-Actor-Id.
    Route::middleware('actor')->group(function () {
        Route::get('my/needs', [NeedController::class, 'mine']);
        Route::post('needs', [NeedController::class, 'store']);
        Route::post('needs/{need}/close', [NeedController::class, 'close']);
        Route::get('needs/{need}/offers', [NeedController::class, 'offers']);
        Route::post('needs/{need}/offers', [NeedController::class, 'offer']);
        Route::post('offers/{offer}/withdraw', [NeedController::class, 'withdraw']);
        Route::post('offers/{offer}/reject', [NeedController::class, 'rejectOffer']);
        Route::post('offers/{offer}/accept', [NeedController::class, 'acceptOffer']);
    });

    // Orders: always as the actor in X-Actor-Id.
    Route::middleware('actor')->group(function () {
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        foreach (['quote', 'accept', 'reject', 'cancel', 'done', 'confirm', 'dispute'] as $step) {
            Route::post("orders/{order}/$step", [OrderController::class, $step]);
        }
    });

    Route::post('media', [MediaController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('media/{media}', [MediaController::class, 'destroy']);
    Route::delete('actors/{actor}/photos/{collection}', [MediaController::class, 'destroySlot']);
});
