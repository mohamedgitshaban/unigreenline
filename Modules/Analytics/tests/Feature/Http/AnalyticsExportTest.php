<?php

namespace Modules\Analytics\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

/**
 * analytics.export is only held by Administrator/Owner/Auditor in this
 * build (Step 8) — no operational role gets it, so "can export" cases use
 * Administrator throughout.
 */
class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_export_expiry_tracking_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['exp_date' => now()->addDays(10), 'qty_cartons' => 20]);

        $this->get('/api/v1/analytics/expiry?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/expiry-tracking-.*\.csv/', function (GenericExport $export) use ($batch) {
            $row = $export->collection()->first();
            $this->assertSame($batch->id, $row[0]);
            $this->assertSame(10, $row[6]);

            return true;
        });
    }

    public function test_sales_manager_without_analytics_export_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/analytics/expiry?export=csv')->assertForbidden();
    }

    public function test_administrator_can_export_stock_rollup_flattened_by_warehouse(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create(['pack_cost_price' => 10, 'carton_qty' => 1]);
        $warehouseA = Warehouse::factory()->recycle($tenant)->create();
        $warehouseB = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product])->for($warehouseA, 'warehouse')->create(['qty_cartons' => 5]);
        InventoryBatch::factory()->recycle([$tenant, $product])->for($warehouseB, 'warehouse')->create(['qty_cartons' => 7]);

        $this->get('/api/v1/analytics/stock?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/stock-.*\.csv/', function (GenericExport $export) {
            // Flattened: one row per product+warehouse, not the nested
            // JSON shape the plain GET returns.
            $this->assertCount(2, $export->collection());

            return true;
        });
    }

    public function test_sales_manager_without_analytics_export_cannot_export_stock(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Sales Manager');
        Sanctum::actingAs($user);

        $this->get('/api/v1/analytics/stock?export=csv')->assertForbidden();
    }
}
