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

class PurchaseOrderUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_updates_header_fields_and_keeps_lines_when_lines_are_omitted(): void
    {
        $this->actingAsRole('Purchasing');
        $po = $this->purchaseOrder(['subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000]);
        $line = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create();

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Call before delivery', 'status' => 'sent', 'expected_date' => '2026-10-15']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'sent');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'notes' => 'Call before delivery', 'total' => 1000]);
        $this->assertDatabaseHas('purchase_order_lines', ['id' => $line->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'PurchaseOrder', 'entity_id' => $po->id, 'operation' => 'UPDATE']);
    }

    public function test_lines_replace_existing_lines_and_totals_are_recalculated(): void
    {
        $this->actingAsRole('Purchasing');
        $po = $this->purchaseOrder(['subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000]);
        $oldLine = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create();
        $product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 14]);

        $response = $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'lines' => [['product_id' => $product->id, 'qty_cartons' => 5, 'cost_per_carton' => 40]],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('purchase_order_lines', ['id' => $oldLine->id]);
        $this->assertDatabaseHas('purchase_order_lines', ['po_id' => $po->id, 'product_id' => $product->id, 'qty_cartons' => 5, 'total' => 200]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'subtotal' => 200, 'tax_amount' => 28, 'total' => 228]);
    }

    public function test_received_order_cannot_be_updated(): void
    {
        $this->actingAsRole('Purchasing');
        $po = $this->purchaseOrder(['status' => 'received', 'stock_added' => true, 'notes' => null]);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Too late'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This purchase order has already been received.');

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'notes' => null]);
    }

    public function test_status_cannot_be_set_to_received(): void
    {
        $this->actingAsRole('Purchasing');
        $po = $this->purchaseOrder();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['status' => 'received'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_user_without_purchasing_edit_is_forbidden(): void
    {
        $this->actingAsRole('Accountant');
        $po = $this->purchaseOrder();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'x'])->assertForbidden();
    }

    public function test_administrator_cannot_update_another_tenants_order(): void
    {
        $this->actingAsRole('Administrator');
        $otherTenant = Tenant::factory()->create();
        $po = PurchaseOrder::factory()->recycle([
            $otherTenant,
            Supplier::factory()->recycle($otherTenant)->create(),
            Warehouse::factory()->recycle($otherTenant)->create(),
        ])->create(['notes' => null]);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'x'])->assertNotFound();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'notes' => null]);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function purchaseOrder(array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::factory()->recycle([
            $this->tenant,
            Supplier::factory()->recycle($this->tenant)->create(),
            Warehouse::factory()->recycle($this->tenant)->create(),
        ])->create($attributes);
    }
}
