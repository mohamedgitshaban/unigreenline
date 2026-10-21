<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_purchase_order_prints_with_prices_once_priced(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create(['name' => 'Nile Vet Supplies']);
        $buyer = User::factory()->recycle($tenant)->create();
        $buyer->assignRole('Purchasing');
        $supplier = Supplier::factory()->recycle($tenant)->create(['name' => 'Pharma Wholesale', 'city' => 'Cairo']);
        $warehouse = Warehouse::factory()->recycle($tenant)->create(['name' => 'Main Depot']);
        $product = Product::factory()->recycle($tenant)->create(['name' => 'Ivermectin', 'sku' => 'IVM-1']);
        $order = PurchaseOrder::factory()->recycle([$tenant, $supplier, $warehouse])->create(['status' => 'sent', 'subtotal' => 900, 'tax_amount' => 126, 'total' => 1026]);
        PurchaseOrderLine::factory()->for($order, 'purchaseOrder')->create(['product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90, 'total' => 900]);
        Sanctum::actingAs($buyer);

        $this->get("/api/v1/purchase-orders/{$order->id}/print")->assertOk()
            ->assertSee('Purchase order')->assertSee('Pharma Wholesale')->assertSee('Main Depot')->assertSee('Ivermectin (IVM-1)')->assertSee('90.00')->assertSee('1,026.00')->assertSee('Supplier');
    }

    public function test_an_unpriced_order_shows_no_prices(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $buyer = User::factory()->recycle($tenant)->create();
        $buyer->assignRole('Purchasing');
        $order = PurchaseOrder::factory()->recycle($tenant)->create(['status' => 'pending_pricing', 'total' => 0]);
        PurchaseOrderLine::factory()->for($order, 'purchaseOrder')->create(['qty_cartons' => 4, 'cost_per_carton' => 0, 'total' => 0]);
        Sanctum::actingAs($buyer);

        $json = $this->getJson("/api/v1/purchase-orders/{$order->id}/print?format=json")->assertOk();

        $json->assertJsonPath('data.tables.0.rows.0.cost', '—')->assertJsonPath('data.totals', []);
    }

    public function test_the_warehouse_manager_cannot_print_purchase_orders_and_foreign_orders_are_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $order = PurchaseOrder::factory()->recycle($tenant)->create();
        $storeman = User::factory()->recycle($tenant)->create();
        $storeman->assignRole('Warehouse Manager');
        Sanctum::actingAs($storeman);

        $this->get("/api/v1/purchase-orders/{$order->id}/print")->assertForbidden();

        $buyer = User::factory()->recycle($tenant)->create();
        $buyer->assignRole('Purchasing');
        Sanctum::actingAs($buyer);
        $this->get('/api/v1/purchase-orders/'.PurchaseOrder::factory()->create()->id.'/print')->assertNotFound();
    }
}
