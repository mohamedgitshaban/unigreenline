<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CityController extends Controller
{
    /**
     * List every Egyptian governorate from the static `core.cities.egypt`
     * reference list. The `slug` is what the cities endpoint expects.
     */
    public function governorates(): JsonResponse
    {
        $governorates = $this->egyptGovernorates()
            ->keys()
            ->map(fn (string $governorate) => ['slug' => Str::slug($governorate), 'name' => $governorate])
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $governorates]);
    }

    /**
     * List the cities of a single governorate, identified by its slug.
     */
    public function cities(string $governorate): JsonResponse
    {
        $cities = $this->egyptGovernorates()
            ->first(fn (array $cities, string $name) => Str::slug($name) === $governorate);

        abort_if($cities === null, 404, 'Governorate not found.');

        return response()->json(['data' => collect($cities)->sort()->values()]);
    }

    /**
     * @return Collection<string, list<string>>
     */
    private function egyptGovernorates(): Collection
    {
        return collect(config('core.cities.egypt'));
    }
}
