<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductUnitService;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Role;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class PurchaseOrderPricingTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 10]);
        Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset']);
        Account::factory()->recycle($this->tenant)->create(['code' => '2100', 'type' => 'Liability']);
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_a_buyers_order_goes_to_pending_pricing_with_no_cost(): void
    {
        $this->actingAsRole('Purchasing');

        $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_pricing')
            ->assertJsonPath('data.total', '0.00');

        $this->assertDatabaseHas('purchase_order_lines', ['qty_cartons' => 10, 'cost_per_carton' => '0.00', 'total' => '0.00']);
    }

    public function test_a_buyer_cannot_set_costs(): void
    {
        $this->actingAsRole('Purchasing');

        $this->postJson('/api/v1/purchase-orders', $this->payload([['product_id' => $this->product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.cost_per_carton');
    }

    public function test_an_administrator_can_still_create_a_priced_order_or_an_unpriced_one(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/purchase-orders', $this->payload([['product_id' => $this->product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '900.00');

        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertCreated()->assertJsonPath('data.status', 'pending_pricing');
    }

    public function test_the_admin_prices_the_order_which_releases_it_and_totals_are_computed(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsRole('Administrator');
        $line = $order->lines()->firstOrFail();

        $this->getJson('/api/v1/purchase-orders/pending-pricing')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);

        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 90]]])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '900.00')
            ->assertJsonPath('data.tax_amount', '90.00')
            ->assertJsonPath('data.total', '990.00');

        $this->assertDatabaseHas('purchase_order_lines', ['id' => $line->id, 'cost_per_carton' => '90.00', 'total' => '900.00']);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'PurchaseOrder', 'entity_id' => $order->id, 'operation' => 'UPDATE']);
        $this->getJson('/api/v1/purchase-orders/pending-pricing')->assertJsonCount(0, 'data');
    }

    public function test_unit_lines_are_priced_per_unit(): void
    {
        $packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
        app(ProductUnitService::class)->sync($this->product, [['unit_id' => $packet->id], ['unit_id' => $carton->id, 'contains' => 30]]);
        $this->actingAsRole('Purchasing');
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload([['product_id' => $this->product->id, 'unit_id' => $packet->id, 'qty' => 60]]))
            ->assertCreated()->assertJsonPath('data.status', 'pending_pricing')->json('data.id');

        $this->actingAsRole('Administrator');
        $line = PurchaseOrder::findOrFail($id)->lines()->firstOrFail();
        $this->putJson("/api/v1/purchase-orders/{$id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_unit' => 5]]])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '300.00');

        $this->assertDatabaseHas('purchase_order_lines', ['id' => $line->id, 'qty_cartons' => 2, 'cost_per_carton' => '150.00', 'cost_per_unit' => '5.0000']);

        $this->putJson("/api/v1/purchase-orders/{$id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 5]]])->assertOk();
    }

    public function test_only_pricers_can_price_or_see_the_queue(): void
    {
        $order = $this->pendingOrder();
        $line = $order->lines()->firstOrFail();

        foreach (['Purchasing', 'Accountant', 'Owner'] as $role) {
            $this->actingAsRole($role);
            $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 1]]])->assertForbidden();
            $this->getJson('/api/v1/purchase-orders/pending-pricing')->assertForbidden();
        }

        $this->assertSame('pending_pricing', $order->fresh()->status);
    }

    public function test_an_admin_can_grant_pricing_to_a_role(): void
    {
        $order = $this->pendingOrder();
        $line = $order->lines()->firstOrFail();
        Role::findByName('Accountant', 'web')->givePermissionTo('purchase-orders.pricing');

        $this->actingAsRole('Accountant');
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 10]]])->assertOk();
    }

    public function test_pricing_must_cover_every_line_exactly_once_with_one_cost(): void
    {
        $order = $this->pendingOrder();
        $order->lines()->create(['product_id' => Product::factory()->recycle($this->tenant)->create()->id, 'qty_cartons' => 1, 'cost_per_carton' => 0, 'total' => 0]);
        $lines = $order->lines()->get();
        $this->actingAsRole('Administrator');

        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $lines[0]->id, 'cost_per_carton' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [
            ['line_id' => $lines[0]->id, 'cost_per_carton' => 5, 'cost_per_unit' => 5], ['line_id' => $lines[1]->id, 'cost_per_carton' => 5],
        ]])->assertUnprocessable();
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [
            ['line_id' => $lines[0]->id, 'cost_per_unit' => 5], ['line_id' => $lines[1]->id, 'cost_per_carton' => 5],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.cost_per_unit');
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [
            ['line_id' => 'nope', 'cost_per_carton' => 5], ['line_id' => $lines[1]->id, 'cost_per_carton' => 5],
        ]])->assertUnprocessable();

        $this->assertSame('pending_pricing', $order->fresh()->status);
    }

    public function test_an_unpriced_order_cannot_be_received_or_released_by_changing_its_status(): void
    {
        $order = $this->pendingOrder();
        $line = $order->lines()->firstOrFail();

        $this->actingAsRole('Administrator');
        $this->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
            'receipts' => [['line_id' => $line->id, 'batch_no' => 'B1', 'exp_date' => now()->addYear()->toDateString()]],
        ])->assertUnprocessable()->assertJsonPath('message', 'This purchase order is pending pricing — an administrator must set the line costs first.');

        $this->actingAsRole('Purchasing');
        $this->putJson("/api/v1/purchase-orders/{$order->id}", ['status' => 'sent'])->assertUnprocessable();
        $this->putJson("/api/v1/purchase-orders/{$order->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_replacing_the_lines_of_a_priced_order_sends_it_back_to_pricing_and_a_priced_order_can_be_repriced(): void
    {
        $order = $this->pendingOrder();
        $line = $order->lines()->firstOrFail();
        $this->actingAsRole('Administrator');
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 10]]])->assertOk();
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 20]]])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.subtotal', '200.00');

        $this->actingAsRole('Purchasing');
        $this->putJson("/api/v1/purchase-orders/{$order->id}", ['lines' => [['product_id' => $this->product->id, 'qty_cartons' => 3]]])
            ->assertOk()->assertJsonPath('data.status', 'pending_pricing')->assertJsonPath('data.total', '0.00');
    }

    public function test_a_priced_order_is_received_as_before(): void
    {
        $order = $this->pendingOrder();
        $line = $order->lines()->firstOrFail();
        $this->actingAsRole('Administrator');
        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 10]]])->assertOk();

        $this->postJson("/api/v1/purchase-orders/{$order->id}/receive", [
            'receipts' => [['line_id' => $line->id, 'batch_no' => 'B1', 'exp_date' => now()->addYear()->toDateString()]],
        ])->assertOk()->assertJsonPath('data.status', 'received');

        $this->putJson("/api/v1/purchase-orders/{$order->id}/pricing", ['lines' => [['line_id' => $line->id, 'cost_per_carton' => 1]]])->assertUnprocessable();
    }

    public function test_another_tenants_order_is_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $foreign = PurchaseOrder::factory()->create(['status' => 'pending_pricing']);

        $this->putJson("/api/v1/purchase-orders/{$foreign->id}/pricing", ['lines' => [['line_id' => 'x', 'cost_per_carton' => 1]]])->assertNotFound();
    }

    private function pendingOrder(): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->recycle([$this->tenant, $this->supplier, $this->warehouse])->create(['status' => 'pending_pricing', 'subtotal' => 0, 'tax_amount' => 0, 'total' => 0]);
        $order->lines()->create(['product_id' => $this->product->id, 'qty_cartons' => 10, 'cost_per_carton' => 0, 'total' => 0]);

        return $order;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function payload(?array $lines = null): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines ?? [['product_id' => $this->product->id, 'qty_cartons' => 10]],
        ];
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
