<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\ProductUnitService;

/**
 * Gives every product that has no packaging ladder yet one, so all products
 * "know" they are stored by the carton: Packet → Carton, where the carton
 * holds the product's existing `carton_qty` packets. Products that already
 * have a ladder are left alone, and re-running is harmless. Adding a middle
 * step (Package) to a product is a PUT /products/{id} with `units`.
 */
class ProductUnitSeeder extends Seeder
{
    public function run(ProductUnitService $service): void
    {
        Product::query()->whereDoesntHave('units')->get()->each(function (Product $product) use ($service) {
            $units = Unit::query()->where('tenant_id', $product->tenant_id)->whereIn('name', ['Packet', 'Carton'])->pluck('id', 'name');

            if ($units->count() < 2 || $product->carton_qty < 2) {
                return;
            }

            $service->sync($product, [
                ['unit_id' => $units['Packet']],
                ['unit_id' => $units['Carton'], 'contains' => $product->carton_qty],
            ]);
        });
    }
}
