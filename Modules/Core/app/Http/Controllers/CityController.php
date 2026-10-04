<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CityController extends Controller
{
    /**
     * List every Egyptian city from the static `core.cities.egypt` reference
     * list, flattened and sorted by governorate then city name.
     */
    public function egypt(): JsonResponse
    {
        $cities = collect(config('core.cities.egypt'))
            ->flatMap(fn (array $cities, string $governorate) => collect($cities)
                ->map(fn (string $city) => ['name' => $city, 'governorate' => $governorate]))
            ->sortBy([['governorate', 'asc'], ['name', 'asc']])
            ->values();

        return response()->json(['data' => $cities]);
    }
}
