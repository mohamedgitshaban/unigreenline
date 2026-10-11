<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ProductLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_builds_a_date_ordered_timeline_from_purchase_through_transfer_to_sale(): void
    {
        $this->actingAsRole('Owner');
        $product = Product::factory()->recycle($this->tenant)->create(['carton_qty' => 10]);
        $main = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main']);
        $branch = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Branch']);
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['name' => 'Acme']);
        $customer = Customer::factory()->recycle($this->tenant)->create(['name' => 'Nile Clinic']);

        $po = PurchaseOrder::factory()->recycle($this->tenant)->create([
            'supplier_id' => $supplier->id, 'warehouse_id' => $main->id, 'status' => 'received',
            'stock_added' => true, 'order_date' => '2026-01-01', 'received_date' => '2026-01-05',
        ]);
        $po->lines()->create(['product_id' => $product->id, 'qty_cartons' => 50, 'cost_per_carton' => 20, 'total' => 1000]);

        Transfer::factory()->recycle($this->tenant)->create([
            'product_id' => $product->id, 'from_warehouse_id' => $main->id, 'to_warehouse_id' => $branch->id,
            'qty_cartons' => 20, 'transfer_date' => '2026-01-10',
        ]);

        $order = SalesOrder::factory()->recycle($this->tenant)->create([
            'customer_id' => $customer->id, 'warehouse_id' => $branch->id, 'status' => 'delivered', 'order_date' => '2026-01-20',
        ]);
        $order->lines()->create(['product_id' => $product->id, 'warehouse_id' => $branch->id, 'qty' => 5, 'unit' => 'Carton', 'unit_price' => 30, 'subtotal' => 150]);

        InventoryBatch::factory()->recycle([$this->tenant, $product, $branch])->withQtyCartons(15)->create();

        $response = $this->getJson("/api/v1/products/{$product->id}/lifecycle")->assertOk();

        $response->assertJsonPath('data.events.*.type', ['purchase_ordered', 'purchase_received', 'transfer', 'sale']);
        $response->assertJsonPath('data.events.1.supplier', 'Acme');
        $response->assertJsonPath('data.events.2.to_warehouse', 'Branch');
        $response->assertJsonPath('data.events.3.customer', 'Nile Clinic');
        $response->assertJsonPath('data.events.3.warehouse', 'Branch');
        $response->assertJsonPath('data.summary.purchased_cartons', 50);
        $response->assertJsonPath('data.summary.transferred_cartons', 20);
        $response->assertJsonPath('data.summary.sold_cartons', 5);
        $response->assertJsonPath('data.summary.on_hand_cartons', 15);

        $this->getJson("/api/v1/products/{$product->id}/lifecycle?from=2026-01-08&to=2026-01-15")
            ->assertJsonPath('data.events.*.type', ['transfer']);
    }

    public function test_requires_permission_and_the_same_tenant(): void
    {
        $product = Product::factory()->recycle($this->tenant)->create();

        $this->actingAsRole('Sales Rep');
        $this->getJson("/api/v1/products/{$product->id}/lifecycle")->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->getJson('/api/v1/products/'.Product::factory()->create()->id.'/lifecycle')->assertNotFound();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
