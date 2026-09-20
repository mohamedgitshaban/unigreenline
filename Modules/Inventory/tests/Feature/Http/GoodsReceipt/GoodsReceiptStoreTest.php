<?php

namespace Modules\Inventory\Tests\Feature\Http\GoodsReceipt;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class GoodsReceiptStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_receives_a_new_batch_with_a_real_expiry_date(): void
    {
        [$user, $product, $warehouse] = $this->arrange();

        $response = $this->postJson('/api/v1/inventory/grn', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 50,
            'cost_per_carton' => 120,
            'exp_date' => '2027-12-31',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('inventory_batches', [
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 50,
            'exp_date' => '2027-12-31',
        ]);
    }

    public function test_expiry_date_is_required_and_never_fabricated(): void
    {
        [$user, $product, $warehouse] = $this->arrange();

        $response = $this->postJson('/api/v1/inventory/grn', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 50,
            'cost_per_carton' => 120,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['exp_date']);
    }

    public function test_forbidden_when_the_warehouse_is_not_assigned_to_the_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create(); // not assigned

        $response = $this->postJson('/api/v1/inventory/grn', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-001',
            'qty_cartons' => 50,
            'cost_per_carton' => 120,
            'exp_date' => '2027-12-31',
        ]);

        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Product, 2: Warehouse}
     */
    private function arrange(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $warehouse->users()->attach($user);

        return [$user, $product, $warehouse];
    }
}
