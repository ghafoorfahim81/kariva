<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\CityResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Province;
use Illuminate\Http\JsonResponse;

/** Reference data: small, public and the same for everybody, so the client may cache it. */
class CatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $roots = Category::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->with('children')->get();

        return response()->json(['data' => CategoryResource::collection($roots)])->header('Cache-Control', 'public, max-age=3600');
    }

    public function provinces(): JsonResponse
    {
        $provinces = Province::orderBy('sort_order')->with('cities')->get()->map(fn ($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'name_ps' => $p->name_ps,
            'name_en' => $p->name_en,
            'cities' => CityResource::collection($p->cities),
        ]);

        return response()->json(['data' => $provinces])->header('Cache-Control', 'public, max-age=3600');
    }

    public function cities(): JsonResponse
    {
        return response()->json(['data' => CityResource::collection(City::orderBy('name')->get())])->header('Cache-Control', 'public, max-age=3600');
    }
}
