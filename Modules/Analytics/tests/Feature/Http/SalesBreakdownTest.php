<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TestCase;

/**
 * Sales grouped by customer, item, rep, category, sub-class, brand and supplier.
 */
class SalesBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private User $repOne;

    private User $repTwo;

    private Customer $nile;

    private Customer $delta;

    private Product $injectable;

    private Product $oral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->manager = User::factory()->recycle($this->tenant)->create(['name' => 'Manager']);
        $this->manager->assignRole('Sales Manager');
        $this->repOne = User::factory()->recycle($this->tenant)->create(['name' => 'Rep One', 'manager_id' => $this->manager->id]);
        $this->repTwo = User::factory()->recycle($this->tenant)->create(['name' => 'Rep Two']);
        $this->nile = Customer::factory()->recycle($this->tenant)->create(['name' => 'Nile Clinic']);
        $this->delta = Customer::factory()->recycle($this->tenant)->create(['name' => 'Delta Farms']);

        $antibiotics = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);
        $injectable = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Injectable', 'parent_id' => $antibiotics->id]);
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['name' => 'EgyVet']);
        $this->injectable = Product::factory()->recycle([$this->tenant, $injectable])->create(['name' => 'Pen-Strep', 'sku' => 'PS-1', 'brand' => 'Acme', 'supplier_id' => $supplier->id]);
        $this->oral = Product::factory()->recycle([$this->tenant, $antibiotics])->create(['name' => 'Oral Mix', 'sku' => 'OM-1', 'brand' => 'Zenith', 'supplier_id' => null]);

        $this->order($this->nile, $this->repOne, 'delivered', '2026-11-05', [[$this->injectable, 600], [$this->oral, 200]]);
        $this->order($this->delta, $this->repTwo, 'invoiced', '2026-11-06', [[$this->injectable, 400]]);
        $this->order($this->delta, $this->repTwo, 'draft', '2026-11-07', [[$this->injectable, 1000]]);
        $this->order($this->nile, $this->repOne, 'delivered', '2026-10-01', [[$this->oral, 50]]);

        Sanctum::actingAs($this->manager);
    }

    public function test_sales_are_grouped_by_customer_biggest_first_and_only_real_sales_count(): void
    {
        $this->breakdown('customer')
            ->assertOk()
            ->assertJsonPath('data.rows.0.name', 'Nile Clinic')
            ->assertJsonPath('data.rows.0.amount', '800.00')
            ->assertJsonPath('data.rows.0.orders', 1)
            ->assertJsonPath('data.rows.1.name', 'Delta Farms')
            ->assertJsonPath('data.rows.1.amount', '400.00')
            ->assertJsonPath('data.total_amount', '1200.00');
    }

    public function test_sales_are_grouped_by_item(): void
    {
        $rows = $this->breakdown('item')->assertOk()->json('data.rows');

        $this->assertSame(['Pen-Strep (PS-1)', 'Oral Mix (OM-1)'], array_column($rows, 'name'));
        $this->assertSame(['1000.00', '200.00'], array_column($rows, 'amount'));
        $this->assertSame([2, 1], array_column($rows, 'orders'));
    }

    public function test_a_sub_class_rolls_up_into_its_class_but_can_also_be_seen_on_its_own(): void
    {
        $this->assertSame(['Antibiotics' => '1200.00'], $this->amounts('category'));
        $this->assertSame(['Injectable' => '1000.00', 'Antibiotics' => '200.00'], $this->amounts('subclass'));
    }

    public function test_sales_are_grouped_by_brand_supplier_and_rep(): void
    {
        $this->assertSame(['Acme' => '1000.00', 'Zenith' => '200.00'], $this->amounts('brand'));
        $this->assertSame(['EgyVet' => '1000.00', '(no supplier)' => '200.00'], $this->amounts('supplier'));
        $this->assertSame(['Rep One' => '800.00', 'Rep Two' => '400.00'], $this->amounts('rep'));
    }

    public function test_the_date_range_narrows_the_figures(): void
    {
        $this->getJson('/api/v1/reports/sales-breakdown?group_by=customer&start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.amount', '50.00');
    }

    public function test_a_manager_limited_to_their_team_only_sees_their_teams_sales(): void
    {
        $this->manager->update(['data_scope' => 'team']);

        $this->assertSame(['Nile Clinic' => '800.00'], $this->amounts('customer'));
    }

    public function test_another_companys_sales_are_not_included(): void
    {
        $foreign = Tenant::factory()->create();
        $this->order(Customer::factory()->recycle($foreign)->create(), User::factory()->recycle($foreign)->create(), 'delivered', '2026-11-05', [[Product::factory()->recycle($foreign)->create(), 9999]]);

        $this->assertSame(['Nile Clinic' => '800.00', 'Delta Farms' => '400.00'], $this->amounts('customer'));
    }

    public function test_a_group_is_required_and_must_be_known(): void
    {
        $this->getJson('/api/v1/reports/sales-breakdown')->assertUnprocessable()->assertJsonValidationErrors('group_by');
        $this->getJson('/api/v1/reports/sales-breakdown?group_by=colour')->assertUnprocessable()->assertJsonValidationErrors('group_by');
    }

    public function test_exporting_needs_the_export_right_which_an_administrator_has(): void
    {
        $this->getJson('/api/v1/reports/sales-breakdown?group_by=customer&export=csv')->assertForbidden();

        $admin = User::factory()->recycle($this->tenant)->create();
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        $this->get('/api/v1/reports/sales-breakdown?group_by=customer&export=csv')->assertOk()->assertDownload();
    }

    public function test_a_role_without_the_report_right_is_forbidden(): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->breakdown('customer')->assertForbidden();
    }

    private function breakdown(string $groupBy): TestResponse
    {
        return $this->getJson("/api/v1/reports/sales-breakdown?group_by={$groupBy}&start_date=2026-11-01&end_date=2026-11-30");
    }

    /**
     * @return array<string, string> Group name => amount, in the order returned.
     */
    private function amounts(string $groupBy): array
    {
        $rows = $this->breakdown($groupBy)->assertOk()->json('data.rows');

        return array_combine(array_column($rows, 'name'), array_column($rows, 'amount'));
    }

    /**
     * @param  array<int, array{0: Product, 1: int|float}>  $lines  Product and line subtotal.
     */
    private function order(Customer $customer, User $rep, string $status, string $date, array $lines): SalesOrder
    {
        $order = SalesOrder::factory()->recycle($customer->tenant)->create(['customer_id' => $customer->id, 'sales_rep_id' => $rep->id, 'status' => $status, 'order_date' => $date]);

        foreach ($lines as [$product, $subtotal]) {
            SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $product->id, 'subtotal' => $subtotal]);
        }

        return $order;
    }
}
