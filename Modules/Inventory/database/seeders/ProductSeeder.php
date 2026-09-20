<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Warehouse;

/**
 * The spec points at the prototype's `002_seed.sql` for exact pack/carton
 * pricing and batch figures to copy — that file wasn't available here, so
 * the values below are realistic placeholders for the same six products,
 * not a byte-for-byte port. Worth swapping for the real figures if/when
 * that file turns up.
 */
class ProductSeeder extends Seeder
{
    private const PRODUCTS = [
        [
            'category' => 'Antibiotics', 'name' => 'Oxytetracycline 20% Injectable', 'sku' => 'PRD-00001',
            'brand' => 'EgyVet', 'pack_unit' => 'Vial 100ml', 'carton_qty' => 20,
            'pack_cost_price' => 45.00, 'pack_selling_price' => 65.00,
            'batches' => [
                ['warehouse' => 'Main Warehouse', 'qty' => 150, 'exp' => '+18 months', 'cost' => 900],
                ['warehouse' => 'Alexandria Branch', 'qty' => 80, 'exp' => '+9 months', 'cost' => 900],
            ],
        ],
        [
            'category' => 'Antiparasitics', 'name' => 'Ivermectin 1% Injection', 'sku' => 'PRD-00002',
            'brand' => 'ArabiVet', 'pack_unit' => 'Bottle 50ml', 'carton_qty' => 24,
            'pack_cost_price' => 30.00, 'pack_selling_price' => 45.00,
            'batches' => [
                ['warehouse' => 'Main Warehouse', 'qty' => 200, 'exp' => '+24 months', 'cost' => 720],
            ],
        ],
        [
            'category' => 'Vaccines', 'name' => 'FMD Vaccine 20 Dose', 'sku' => 'PRD-00003',
            'brand' => 'ImmunoVet Biologics', 'pack_unit' => 'Vial 20-dose', 'carton_qty' => 10,
            'pack_cost_price' => 120.00, 'pack_selling_price' => 170.00,
            'batches' => [
                ['warehouse' => 'Cold Storage', 'qty' => 60, 'exp' => '+6 months', 'cost' => 1200],
                ['warehouse' => 'Cold Storage', 'qty' => 40, 'exp' => '+14 months', 'cost' => 1200],
            ],
        ],
        [
            'category' => 'Vitamins & Supplements', 'name' => 'Multivitamin AD3E Injection', 'sku' => 'PRD-00004',
            'brand' => 'EgyVet', 'pack_unit' => 'Bottle 100ml', 'carton_qty' => 20,
            'pack_cost_price' => 18.00, 'pack_selling_price' => 28.00,
            'batches' => [
                ['warehouse' => 'Main Warehouse', 'qty' => 300, 'exp' => '+20 months', 'cost' => 360],
            ],
        ],
        [
            'category' => 'Antibiotics', 'name' => 'Amoxicillin 15% LA Injection', 'sku' => 'PRD-00005',
            'brand' => 'ArabiVet', 'pack_unit' => 'Vial 100ml', 'carton_qty' => 20,
            'pack_cost_price' => 50.00, 'pack_selling_price' => 72.00,
            'batches' => [
                ['warehouse' => 'Main Warehouse', 'qty' => 100, 'exp' => '+3 months', 'cost' => 1000],
                ['warehouse' => 'Alexandria Branch', 'qty' => 60, 'exp' => '+16 months', 'cost' => 1000],
            ],
        ],
        [
            'category' => 'Disinfectants', 'name' => 'Glutaraldehyde Disinfectant 5L', 'sku' => 'PRD-00006',
            'brand' => 'EgyVet', 'pack_unit' => 'Jerrycan 5L', 'carton_qty' => 4,
            'pack_cost_price' => 80.00, 'pack_selling_price' => 110.00,
            'batches' => [
                ['warehouse' => 'Main Warehouse', 'qty' => 120, 'exp' => '+30 months', 'cost' => 320],
            ],
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();
        $warehouses = Warehouse::query()->where('tenant_id', $tenant->id)->get()->keyBy('name');

        foreach (self::PRODUCTS as $data) {
            $category = ProductCategory::query()->where('tenant_id', $tenant->id)->where('name', $data['category'])->firstOrFail();

            $product = Product::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $data['sku']],
                [
                    'category_id' => $category->id,
                    'name' => $data['name'],
                    'brand' => $data['brand'],
                    'pack_unit' => $data['pack_unit'],
                    'carton_qty' => $data['carton_qty'],
                    'pack_cost_price' => $data['pack_cost_price'],
                    'pack_selling_price' => $data['pack_selling_price'],
                    'tax_pct' => 14,
                    'min_stock_cartons' => 10,
                    'reorder_level' => 20,
                    'active' => true,
                ]
            );

            foreach ($data['batches'] as $index => $batch) {
                $warehouse = $warehouses[$batch['warehouse']];

                InventoryBatch::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'batch_no' => sprintf('%s-B%d', $data['sku'], $index + 1),
                        'warehouse_id' => $warehouse->id,
                    ],
                    [
                        'product_id' => $product->id,
                        'mfg_date' => now()->subMonths(3),
                        'exp_date' => now()->modify($batch['exp']),
                        'rcv_date' => now()->subDays(30),
                        'qty_cartons' => $batch['qty'],
                        'qty_packs' => 0,
                        'cost_per_carton' => $batch['cost'],
                    ]
                );
            }

            $warehouseIds = collect($data['batches'])->pluck('warehouse')->unique()
                ->map(fn (string $name) => $warehouses[$name]->id);

            foreach ($warehouseIds as $warehouseId) {
                Warehouse::find($warehouseId)->recalculateStockValue();
            }
        }
    }
}
