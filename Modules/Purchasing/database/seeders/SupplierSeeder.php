<?php

namespace Modules\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Product;
use Modules\Purchasing\Models\Supplier;

/**
 * Three suppliers per spec §7. Also backfills products.supplier_id, which
 * the Inventory seeder (Step 2) left null since suppliers didn't exist
 * yet — matched by the product's brand string, which was already seeded
 * with these exact supplier names in mind.
 */
class SupplierSeeder extends Seeder
{
    private const SUPPLIERS = [
        ['name' => 'EgyVet Pharmaceutical', 'country' => 'Egypt', 'city' => 'Cairo'],
        ['name' => 'ImmunoVet Biologics', 'country' => 'Germany', 'city' => 'Berlin'],
        ['name' => 'ArabiVet Industries', 'country' => 'Egypt', 'city' => 'Alexandria'],
    ];

    /** Product.brand => Supplier.name */
    private const BRAND_TO_SUPPLIER = [
        'EgyVet' => 'EgyVet Pharmaceutical',
        'ImmunoVet Biologics' => 'ImmunoVet Biologics',
        'ArabiVet' => 'ArabiVet Industries',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();

        $suppliersByName = collect(self::SUPPLIERS)->mapWithKeys(function (array $data) use ($tenant) {
            $supplier = Supplier::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $data['name']],
                ['country' => $data['country'], 'city' => $data['city'], 'pay_terms' => 'Net 30', 'currency' => 'EGP', 'status' => 'active']
            );

            return [$data['name'] => $supplier];
        });

        foreach (self::BRAND_TO_SUPPLIER as $brand => $supplierName) {
            Product::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand', $brand)
                ->whereNull('supplier_id')
                ->update(['supplier_id' => $suppliersByName[$supplierName]->id]);
        }
    }
}
