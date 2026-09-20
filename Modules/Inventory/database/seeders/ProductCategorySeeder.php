<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\ProductCategory;

class ProductCategorySeeder extends Seeder
{
    private const CATEGORIES = [
        'Antibiotics', 'Antiparasitics', 'Vaccines', 'Vitamins & Supplements', 'Hormones', 'Disinfectants',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();

        foreach (self::CATEGORIES as $name) {
            ProductCategory::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['active' => true],
            );
        }
    }
}
