<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class SalesOrderStoreAndDeliverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Customer $customer;

    private Product $product;

    private InventoryBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->customer = Customer::factory()->recycle($this->tenant)->create(['credit_limit' => 100000, 'balance' => 0]);
        $this->product = Product::factory()->recycle($this->tenant)->create(['tax_pct' => 10]);
        $this->batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->warehouse])->withQtyCartons(10)->create();
        foreach (['1200' => 'Asset', '4100' => 'Revenue', '2300' => 'Liability'] as $code => $type) {
            Account::factory()->recycle($this->tenant)->create(['code' => $code, 'type' => $type, 'balance' => 0]);
        }
    }

    public function test_creates_the_order_and_delivers_it_in_one_call(): void
    {
        Storage::fake();
        $this->actingAsRole('Sales Manager');
        $signed = UploadedFile::fake()->image('signed.jpg')->store('delivery-invoices/'.$this->tenant->id);

        $response = $this->postJson('/api/v1/sales-orders/deliver', $this->payload([
            'delivery' => ['driver' => 'Hassan', 'delivery_date' => '2026-10-12', 'notes' => 'Left at the gate', 'invoice_data' => $signed],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.total', '440.00')
            ->assertJsonPath('data.invoices_count', 1)
            ->assertJsonPath('data.delivery.status', 'delivered')
            ->assertJsonPath('data.delivery.driver', 'Hassan');

        $this->assertSame(6, $this->batch->fresh()->qty_cartons);
        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'total' => '440.00']);
        $this->assertDatabaseHas('deliveries', ['customer_id' => $this->customer->id, 'status' => 'delivered', 'driver' => 'Hassan', 'notes' => 'Left at the gate', 'invoice_data' => $signed]);
        $this->assertSame('440.00', Account::query()->where('code', '1200')->first()->balance);
        $this->assertSame('400.00', Account::query()->where('code', '4100')->first()->balance);
    }

    public function test_the_delivery_block_is_optional(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.delivery.status', 'delivered');
    }

    public function test_a_failure_leaves_nothing_behind(): void
    {
        $this->actingAsRole('Sales Manager');

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload(['lines' => [['product_id' => $this->product->id, 'qty' => 99, 'unit' => 'Carton', 'unit_price' => 100]]]))
            ->assertUnprocessable()
            ->assertJsonStructure(['shortfalls']);

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(10, $this->batch->fresh()->qty_cartons);
    }

    public function test_a_sales_rep_delivers_under_their_own_name_and_other_roles_are_refused(): void
    {
        $rep = $this->actingAsRole('Sales Rep');

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.sales_rep.id', $rep->id);

        $this->actingAsRole('Customer Service');
        $this->postJson('/api/v1/sales-orders/deliver', $this->payload())->assertForbidden();
    }

    public function test_the_same_validation_as_a_normal_order_applies(): void
    {
        $this->actingAsRole('Sales Manager');
        $this->product->update(['min_selling_price' => 100]);

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload(['lines' => [['product_id' => $this->product->id, 'qty' => 1, 'unit' => 'Pack', 'unit_price' => 50]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.unit_price');

        $this->postJson('/api/v1/sales-orders/deliver', $this->payload(['delivery' => ['invoice_data' => 'not/uploaded.pdf']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery.invoice_data');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'pay_type' => 'credit',
            'lines' => [['product_id' => $this->product->id, 'qty' => 4, 'unit' => 'Carton', 'unit_price' => 100]],
        ];
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
