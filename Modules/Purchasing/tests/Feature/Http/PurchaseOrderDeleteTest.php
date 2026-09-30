<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_deletes_an_unreceived_order_and_its_lines(): void
    {
        $this->actingAsDeleter();
        $po = $this->purchaseOrder();
        $line = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create();

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertNoContent();

        $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
        $this->assertDatabaseMissing('purchase_order_lines', ['id' => $line->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'PurchaseOrder', 'entity_id' => $po->id, 'operation' => 'DELETE']);
    }

    public function test_received_order_cannot_be_deleted(): void
    {
        $this->actingAsDeleter();
        $po = $this->purchaseOrder(['status' => 'received', 'stock_added' => true, 'received_date' => now()->toDateString()]);

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This purchase order has already been received.');

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
    }

    public function test_user_without_purchasing_delete_is_forbidden(): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);
        $po = $this->purchaseOrder();

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertForbidden();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
    }

    public function test_administrator_cannot_delete_another_tenants_order(): void
    {
        $admin = User::factory()->recycle($this->tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $otherTenant = Tenant::factory()->create();
        $po = PurchaseOrder::factory()->recycle([
            $otherTenant,
            Supplier::factory()->recycle($otherTenant)->create(),
            Warehouse::factory()->recycle($otherTenant)->create(),
        ])->create();

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertNotFound();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
    }

    /**
     * No seeded role besides Administrator has purchasing.delete, and
     * Administrator bypasses policies — so grant it directly to exercise
     * the real policy check.
     */
    private function actingAsDeleter(): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Purchasing');
        $user->givePermissionTo('purchasing.delete');
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
