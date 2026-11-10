<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\TenantSetting;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * The order header and totals: who the order belongs to, cash vs credit terms, delivery date, shipping,
 * discount as a percentage, lines without a warehouse, repeat orders and the quick-sale path.
 */
class SalesOrderCheckoutTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000, 'balance' => 0]);
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 10]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(10)->create();
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_an_inactive_product_cannot_be_ordered(): void
    {
        $this->product->update(['active' => false]);
        $this->actingAsRole('Sales Manager');

        $this->postOrder()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.product_id' => 'This product is inactive or does not exist, so it cannot be used.']);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_the_order_goes_to_the_customers_rep_when_none_is_given(): void
    {
        $rep = User::factory()->recycle($this->tenant)->create();
        $this->customer->update(['sales_rep_id' => $rep->id]);
        $this->actingAsRole('Sales Manager');

        $this->postOrder()
            ->assertCreated()
            ->assertJsonPath('data.sales_rep.id', $rep->id);
    }

    public function test_the_order_stays_with_the_caller_when_the_customers_rep_is_outside_their_team(): void
    {
        $otherRep = User::factory()->recycle($this->tenant)->create();
        $this->customer->update(['sales_rep_id' => $otherRep->id]);
        $caller = $this->actingAsRole('Sales Rep');

        $this->postOrder()
            ->assertCreated()
            ->assertJsonPath('data.sales_rep.id', $caller->id);
    }

    public function test_a_cash_order_ignores_the_grace_period_and_a_credit_order_keeps_it(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole('Sales Manager');

        $this->postOrder(['pay_type' => 'cash', 'grace_period' => 30])
            ->assertCreated()
            ->assertJsonPath('data.grace_period', 0)
            ->assertJsonPath('data.due_date', null);

        $this->postOrder(['pay_type' => 'credit', 'grace_period' => 30])
            ->assertCreated()
            ->assertJsonPath('data.grace_period', 30)
            ->assertJsonPath('data.due_date', '2026-12-10');
    }

    public function test_switching_an_order_to_cash_clears_its_grace_period(): void
    {
        $this->actingAsRole('Sales Manager');
        $id = $this->postOrder(['pay_type' => 'credit', 'grace_period' => 30])->json('data.id');

        $this->putJson("/api/v1/sales-orders/{$id}", ['pay_type' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.grace_period', 0)
            ->assertJsonPath('data.due_date', null);
    }

    public function test_the_requested_delivery_date_is_saved_and_returned(): void
    {
        $this->actingAsRole('Sales Manager');

        $id = $this->postOrder(['delivery_date' => '2026-11-20'])
            ->assertCreated()
            ->assertJsonPath('data.delivery_date', '2026-11-20')
            ->json('data.id');

        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'delivery_date' => '2026-11-20']);
    }

    public function test_shipping_is_the_companys_own_cost_by_default_and_not_added_to_the_total(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postOrder(['shipping_cost' => 25])
            ->assertCreated()
            ->assertJsonPath('data.shipping_cost', '25.00')
            ->assertJsonPath('data.shipping_charged', false)
            ->assertJsonPath('data.total', '440.00');
    }

    public function test_shipping_is_added_to_the_total_and_booked_as_income_when_the_company_charges_it(): void
    {
        TenantSetting::forTenant($this->tenant->id)->fill(['shipping_cost_paid_by_company' => false])->save();
        $this->actingAsRole('Sales Manager');

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload(['shipping_cost' => 25]))
            ->assertCreated()
            ->assertJsonPath('data.shipping_charged', true)
            ->assertJsonPath('data.total', '465.00');

        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'shipping_amount' => '25.00', 'total' => '465.00']);
        $this->assertSame('25.00', Account::query()->where('tenant_id', $this->tenant->id)->where('code', '4200')->first()->balance);
        $this->assertSame('465.00', Account::query()->where('tenant_id', $this->tenant->id)->where('code', '1200')->first()->balance);
    }

    public function test_the_invoice_discount_can_be_given_as_a_percentage_of_the_subtotal(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postOrder(['invoice_discount_pct' => 10])
            ->assertCreated()
            ->assertJsonPath('data.invoice_discount', '40.00')
            ->assertJsonPath('data.invoice_discount_pct', '10.00')
            ->assertJsonPath('data.total', '400.00');
    }

    public function test_a_flat_discount_and_a_percentage_together_are_refused(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postOrder(['invoice_discount' => 5, 'invoice_discount_pct' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_discount_pct' => 'Send either invoice_discount or invoice_discount_pct, not both.']);
    }

    public function test_a_line_without_a_warehouse_uses_the_warehouse_given_for_the_order(): void
    {
        $this->actingAsRole('Sales Manager');

        $id = $this->postOrder([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 2, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('sales_order_lines', ['so_id' => $id, 'warehouse_id' => $this->warehouse->id]);
    }

    public function test_a_line_without_a_warehouse_is_taken_from_the_warehouse_that_holds_the_stock(): void
    {
        $other = Warehouse::factory()->recycle($this->tenant)->create();
        $stocked = Product::factory()->recycle($this->tenant)->create();
        InventoryBatch::factory()->recycle([$this->tenant, $stocked, $other])->withQtyCartons(5)->create();
        $this->actingAsRole('Sales Manager');

        $id = $this->postOrder([
            'lines' => [['product_id' => $stocked->id, 'qty' => 2, 'unit' => 'Carton', 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('sales_order_lines', ['so_id' => $id, 'warehouse_id' => $other->id]);
    }

    public function test_a_shortage_names_the_product_and_the_warehouse(): void
    {
        $unstocked = Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin 1%', 'sku' => 'IVM-1']);
        $this->actingAsRole('Sales Manager');

        $this->postOrder(['lines' => [['product_id' => $unstocked->id, 'qty' => 2, 'unit' => 'Carton', 'unit_price' => 100]]])
            ->assertUnprocessable()
            ->assertJsonPath('shortfalls.0.product_name', 'Ivermectin 1%')
            ->assertJsonPath('shortfalls.0.sku', 'IVM-1')
            ->assertJsonPath('shortfalls.0.warehouse_name', $this->warehouse->name)
            ->assertJsonPath('shortfalls.0.needed', 2);

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_a_draft_can_be_duplicated_into_a_new_draft(): void
    {
        $this->actingAsRole('Sales Manager');
        $source = $this->postOrder(['notes' => 'Weekly order', 'invoice_discount_pct' => 10])->assertCreated()->json('data');

        $copy = $this->postJson("/api/v1/sales-orders/{$source['id']}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.notes', 'Weekly order')
            ->assertJsonPath('data.total', $source['total'])
            ->assertJsonPath('data.lines_count', 1)
            ->json('data');

        $this->assertNotSame($source['id'], $copy['id']);
        $this->assertNotSame($source['number'], $copy['number']);
        $this->assertDatabaseCount('sales_orders', 2);
    }

    public function test_a_copy_is_refused_when_the_stock_is_no_longer_there(): void
    {
        $this->actingAsRole('Sales Manager');
        $source = $this->postOrder(['lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 6, 'unit' => 'Carton', 'unit_price' => 100]]])->json('data');
        $this->postOrder(['lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 6, 'unit' => 'Carton', 'unit_price' => 100]]])->assertCreated();
        $this->putJson('/api/v1/sales-orders/'.$source['id'].'/status', ['status' => 'invoiced'])->assertOk();

        $this->postJson('/api/v1/sales-orders/'.$source['id'].'/duplicate')
            ->assertUnprocessable()
            ->assertJsonStructure(['shortfalls']);
    }

    public function test_a_customer_service_user_cannot_duplicate_an_order(): void
    {
        $this->actingAsRole('Sales Manager');
        $id = $this->postOrder()->json('data.id');

        $this->actingAsRole('Customer Service');
        $this->postJson("/api/v1/sales-orders/{$id}/duplicate")->assertForbidden();
    }

    public function test_the_next_order_number_is_previewed_without_being_used(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->getJson('/api/v1/sales-orders/next-number')->assertOk()->assertJsonPath('data.number', 'SO-000001');
        $this->getJson('/api/v1/sales-orders/next-number')->assertOk()->assertJsonPath('data.number', 'SO-000001');

        $this->postOrder()->assertCreated()->assertJsonPath('data.number', 'SO-000001');

        $this->getJson('/api/v1/sales-orders/next-number')->assertOk()->assertJsonPath('data.number', 'SO-000002');
    }

    public function test_the_quick_sale_path_on_invoices_creates_and_delivers_the_order(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postJson('/api/v1/invoices/quick-sale', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.invoices_count', 1);

        $this->actingAsRole('Customer Service');
        $this->postJson('/api/v1/invoices/quick-sale', $this->payload())->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id,
            'pay_type' => 'credit',
            'lines' => [['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 4, 'unit' => 'Carton', 'unit_price' => 100]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postOrder(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/sales-orders', $this->payload($overrides));
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
