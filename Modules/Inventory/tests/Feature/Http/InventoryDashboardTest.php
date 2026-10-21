<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\StockMovementContext;
use Tests\TestCase;

class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_warehouse_manager_sees_only_the_figures_of_their_own_warehouses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Warehouse Manager');
        $mine = Warehouse::factory()->recycle($tenant)->create();
        $other = Warehouse::factory()->recycle($tenant)->create();
        $mine->users()->attach($manager);
        $product = Product::factory()->recycle($tenant)->create(['min_stock_cartons' => 100]);
        $batch = InventoryBatch::factory()->recycle([$tenant, $product, $mine])->create(['qty_cartons' => 40, 'exp_date' => now()->addDays(5)->toDateString()]);
        InventoryBatch::factory()->recycle([$tenant, $product, $other])->create(['qty_cartons' => 900]);
        StockMovementContext::run(['type' => 'sale'], fn () => $batch->decrement('qty_cartons', 10));
        Sanctum::actingAs($manager);

        $data = $this->getJson('/api/v1/dashboards/inventory')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertSame(30, $kpis['cartons_on_hand']['value']);
        $this->assertSame(1, $kpis['products_in_stock']['value']);
        $this->assertSame(1, $kpis['low_stock_products']['value']);
        $this->assertSame(1, $kpis['expiring_batches']['value']);
        $this->assertSame(10, $kpis['issued_cartons']['value']);
        $this->assertSame(40, $kpis['received_cartons']['value']);
        $this->assertArrayNotHasKey('stock_value', $kpis->all(), 'costs stay with the administrator');
        $this->assertSame([30], collect(collect($data['charts'])->firstWhere('key', 'stock_by_warehouse')['series'])->pluck('value')->all());
    }
}
