<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class SalesOrderUpdateDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 10, 'carton_qty' => 10]);
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(20)->create();
    }

    public function test_edits_a_draft_orders_header_and_replaces_its_lines_with_new_totals(): void
    {
        $this->actingAsRole('Sales Manager');
        $order = $this->draftOrder();

        $this->putJson("/api/v1/sales-orders/{$order->id}", [
            'notes' => 'Rush',
            'invoice_discount' => 10,
            'lines' => [['product_id' => $this->product->id, 'qty' => 4, 'unit' => 'Carton', 'unit_price' => 100]],
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Rush');

        // 4 × 100 = 400, +10% tax = 40, −10 discount.
        $order->refresh();
        $this->assertSame('400.00', $order->subtotal);
        $this->assertSame('40.00', $order->tax_amount);
        $this->assertSame('430.00', $order->total);
        $this->assertSame(1, $order->lines()->count());
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'SalesOrder', 'entity_id' => $order->id, 'operation' => 'UPDATE']);
    }

    public function test_an_edit_that_asks_for_more_stock_than_exists_is_refused_and_changes_nothing(): void
    {
        $this->actingAsRole('Sales Manager');
        $order = $this->draftOrder();

        $this->putJson("/api/v1/sales-orders/{$order->id}", [
            'lines' => [['product_id' => $this->product->id, 'qty' => 500, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonStructure(['shortfalls']);

        $this->assertSame(1, $order->lines()->count());
        $this->assertSame('100.00', $order->fresh()->subtotal);
    }

    public function test_only_draft_orders_can_be_edited_or_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draftOrder(['status' => 'invoiced']);

        $this->putJson("/api/v1/sales-orders/{$order->id}", ['notes' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only draft sales orders can be changed or deleted.');
        $this->deleteJson("/api/v1/sales-orders/{$order->id}")->assertUnprocessable();
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
    }

    public function test_deletes_a_draft_order_with_its_lines(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draftOrder();

        $this->deleteJson("/api/v1/sales-orders/{$order->id}")->assertNoContent();

        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('sales_order_lines', ['so_id' => $order->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'SalesOrder', 'entity_id' => $order->id, 'operation' => 'DELETE']);
    }

    public function test_delete_needs_the_delete_permission_and_a_rep_only_touches_their_own_orders(): void
    {
        $order = $this->draftOrder();

        $this->actingAsRole('Sales Manager');
        $this->deleteJson("/api/v1/sales-orders/{$order->id}")->assertForbidden();

        $rep = $this->actingAsRole('Sales Rep');
        $this->putJson("/api/v1/sales-orders/{$order->id}", ['notes' => 'mine?'])->assertForbidden();
        $this->assertNotSame($rep->id, $order->sales_rep_id);
    }

    public function test_another_tenants_order_is_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $foreign = SalesOrder::factory()->create();

        $this->putJson("/api/v1/sales-orders/{$foreign->id}", ['notes' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/sales-orders/{$foreign->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draftOrder(array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->recycle($this->tenant)->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'subtotal' => 100, 'tax_amount' => 10, 'total' => 110,
        ] + $attributes);
        $order->lines()->create(['product_id' => $this->product->id, 'qty' => 1, 'unit' => 'Carton', 'unit_price' => 100, 'discount_pct' => 0, 'free_qty' => 0, 'subtotal' => 100]);

        return $order;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }
}
