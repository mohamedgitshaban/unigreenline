<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TestCase;

class SalesOrderStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_rep_can_advance_their_own_order(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        Sanctum::actingAs($rep);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'picking']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'picking');
    }

    public function test_sales_rep_cannot_advance_another_reps_order(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        $otherRep = User::factory()->recycle($tenant)->create();
        $otherRep->assignRole('Sales Rep');
        Sanctum::actingAs($otherRep);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'picking']);

        $response->assertForbidden();
    }

    public function test_invalid_transition_returns_422(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        Sanctum::actingAs($rep);
        $order->update(['status' => 'delivered']);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'picking']);

        $response->assertUnprocessable();
    }

    public function test_transition_to_invoiced_returns_the_created_invoice_reference(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        $this->seedAccounts($tenant);
        Sanctum::actingAs($rep);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'invoiced']);

        $response->assertOk();
        $this->assertDatabaseHas('invoices', ['so_id' => $order->id]);
        $response->assertJsonPath('data.invoices.0.sales_order.id', $order->id);
    }

    /**
     * Regression: SalesOrderResource used to omit `invoices`/`delivery`
     * entirely despite the service eager-loading both and the docs
     * documenting them — found via an end-to-end Postman collection run
     * that couldn't chain into Invoices/Deliveries/Collections at all
     * because there was nowhere to read the created ids from.
     */
    public function test_transition_to_delivered_returns_both_the_invoice_and_delivery_reference(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        $this->seedAccounts($tenant);
        Sanctum::actingAs($rep);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'delivered']);

        $response->assertOk();
        $response->assertJsonPath('data.invoices.0.sales_order.id', $order->id);
        $response->assertJsonPath('data.delivery.sales_order.id', $order->id);
        $response->assertJsonPath('data.delivery.status', 'delivered');
    }

    /**
     * Regression: naively wrapping the (loaded but null) `delivery` relation
     * in `new DeliveryResource(...)` crashes with a 500 on any property
     * access, since it's genuinely null before the order reaches
     * `delivered` — this locks in the null-safe fix.
     */
    public function test_transition_to_invoiced_alone_returns_a_null_delivery_without_erroring(): void
    {
        [$tenant, $rep, $order] = $this->arrange();
        $this->seedAccounts($tenant);
        Sanctum::actingAs($rep);

        $response = $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'invoiced']);

        $response->assertOk();
        $response->assertJsonPath('data.delivery', null);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: SalesOrder}
     */
    private function arrange(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');

        $customer = Customer::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])->withQtyCartons(100)->create();

        $order = SalesOrder::factory()->recycle([$tenant, $customer, $warehouse])->create([
            'sales_rep_id' => $rep->id,
            'status' => 'draft',
            'subtotal' => 325,
            'tax_amount' => 45.5,
            'total' => 370.5,
        ]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $product->id, 'qty' => 5]);

        return [$tenant, $rep, $order];
    }

    private function seedAccounts(Tenant $tenant): void
    {
        foreach (['1200' => 'Asset', '4100' => 'Revenue', '2300' => 'Liability'] as $code => $type) {
            Account::factory()->recycle($tenant)->create(['code' => $code, 'type' => $type]);
        }
    }
}
