<?php

namespace Modules\Inventory\Tests\Feature\Http\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Warehouse;
use Spatie\Permission\Models\Role;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Setting a product up: an automatic code, the brand type-ahead, lead time, and stock entered with the product.
 */
class ProductSetupTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private ProductCategory $category;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->category = ProductCategory::factory()->recycle($this->tenant)->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->user = User::factory()->recycle($this->tenant)->create();
        $this->user->assignRole('Warehouse Manager');
        $this->warehouse->users()->attach($this->user);
        Sanctum::actingAs($this->user);
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_a_product_without_a_code_gets_the_next_free_one(): void
    {
        $this->postProduct(['name' => 'First'])->assertCreated()->assertJsonPath('data.sku', 'PRD-00001');
        $this->postProduct(['name' => 'Second'])->assertCreated()->assertJsonPath('data.sku', 'PRD-00002');
    }

    public function test_automatic_codes_skip_codes_that_were_typed_by_hand(): void
    {
        $this->postProduct(['name' => 'Typed', 'sku' => 'PRD-00001'])->assertCreated();
        $this->postProduct(['name' => 'Automatic'])->assertCreated()->assertJsonPath('data.sku', 'PRD-00002');
    }

    public function test_a_code_typed_by_hand_is_kept(): void
    {
        $this->postProduct(['name' => 'Manufacturer code', 'sku' => 'AMX-1000'])->assertCreated()->assertJsonPath('data.sku', 'AMX-1000');
    }

    public function test_the_lead_time_is_saved_and_returned(): void
    {
        $id = $this->postProduct(['name' => 'Slow mover', 'lead_time_days' => 14])
            ->assertCreated()
            ->assertJsonPath('data.lead_time_days', 14)
            ->json('data.id');

        $this->putJson("/api/v1/products/{$id}", ['lead_time_days' => 5])->assertOk()->assertJsonPath('data.lead_time_days', 5);
    }

    public function test_brands_already_in_use_are_offered_for_the_form(): void
    {
        Product::factory()->recycle([$this->tenant, $this->category])->create(['brand' => 'Acme']);
        Product::factory()->recycle([$this->tenant, $this->category])->create(['brand' => 'Acme']);
        Product::factory()->recycle([$this->tenant, $this->category])->create(['brand' => 'Zenith']);
        Product::factory()->recycle([$this->tenant, $this->category])->create(['brand' => null]);
        Product::factory()->create(['brand' => 'Someone else']);

        $this->getJson('/api/v1/brands')->assertOk()->assertExactJson(['data' => [['name' => 'Acme'], ['name' => 'Zenith']]]);
        $this->getJson('/api/v1/brands?search=zen')->assertOk()->assertExactJson(['data' => [['name' => 'Zenith']]]);
    }

    public function test_opening_stock_is_received_into_a_batch_with_the_product(): void
    {
        $response = $this->postProduct(['name' => 'With stock', 'opening_stock' => $this->openingStock()])->assertCreated();

        $batch = InventoryBatch::query()->where('product_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame(12, $batch->qty_cartons);
        $this->assertSame($this->warehouse->id, $batch->warehouse_id);
        $this->assertDatabaseHas('stock_movements', ['batch_id' => $batch->id, 'type' => 'goods_receipt', 'qty_cartons' => 12]);
        $this->assertSame('600.00', Account::query()->where('tenant_id', $this->tenant->id)->where('code', '1300')->first()->balance);
        $this->assertSame('600.00', Account::query()->where('tenant_id', $this->tenant->id)->where('code', '3300')->first()->balance);
    }

    public function test_a_failed_opening_stock_leaves_no_product_behind(): void
    {
        $this->postProduct(['name' => 'Bad stock', 'opening_stock' => ['qty_cartons' => 0] + $this->openingStock()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_stock.qty_cartons');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_opening_stock_needs_the_right_to_receive_stock_into_that_warehouse(): void
    {
        $role = Role::create(['name' => 'Catalogue Clerk', 'guard_name' => 'web']);
        $role->givePermissionTo(['products.create', 'products.view']);
        $clerk = User::factory()->recycle($this->tenant)->create();
        $clerk->assignRole($role);
        Sanctum::actingAs($clerk);

        $this->postProduct(['name' => 'No receipt right', 'opening_stock' => $this->openingStock()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_stock.warehouse_id');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_an_inactive_product_cannot_be_received_into_stock(): void
    {
        $product = Product::factory()->recycle([$this->tenant, $this->category])->create(['active' => false]);

        $this->postJson('/api/v1/inventory/grn', $this->openingStock() + ['product_id' => $product->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id' => 'This product is inactive or does not exist, so it cannot be used.']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postProduct(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/products', $overrides + [
            'category_id' => $this->category->id,
            'name' => 'Product',
            'pack_unit' => 'Vial',
            'carton_qty' => 10,
            'pack_cost_price' => 5,
            'pack_selling_price' => 8,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openingStock(): array
    {
        return ['warehouse_id' => $this->warehouse->id, 'batch_no' => 'OPEN-1', 'qty_cartons' => 12, 'cost_per_carton' => 50, 'exp_date' => '2028-01-31'];
    }
}
