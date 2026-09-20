<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderReceiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchasing_role_receives_the_order_and_adds_stock(): void
    {
        [$tenant, $po, $line, $product, $warehouse] = $this->arrange();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);
        Account::factory()->recycle($tenant)->create(['code' => '1300', 'type' => 'Asset']);
        Account::factory()->recycle($tenant)->create(['code' => '2100', 'type' => 'Liability']);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [
                ['line_id' => $line->id, 'batch_no' => 'BATCH-001', 'exp_date' => '2027-12-31'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'received');
        $this->assertDatabaseHas('inventory_batches', ['batch_no' => 'BATCH-001', 'product_id' => $product->id]);
    }

    public function test_missing_expiry_date_is_rejected(): void
    {
        [$tenant, $po, $line] = $this->arrange();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [
                ['line_id' => $line->id, 'batch_no' => 'BATCH-001'],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['receipts.0.exp_date']);
    }

    public function test_role_without_approve_permission_is_forbidden(): void
    {
        [$tenant, $po, $line] = $this->arrange();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [
                ['line_id' => $line->id, 'batch_no' => 'BATCH-001', 'exp_date' => '2027-12-31'],
            ],
        ]);

        $response->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: PurchaseOrder, 2: PurchaseOrderLine, 3: Product, 4: Warehouse}
     */
    private function arrange(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $po = PurchaseOrder::factory()->recycle([$tenant, $supplier, $warehouse])->create([
            'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
        ]);
        $line = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create([
            'product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 100, 'total' => 1000,
        ]);

        return [$tenant, $po, $line, $product, $warehouse];
    }
}
