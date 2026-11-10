<?php

namespace Modules\Analytics\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

/**
 * Stock by product, warehouse and batch on a given day.
 */
class StockSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $main;

    private Warehouse $branch;

    private Product $tonic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->travelTo('2026-10-01 09:00:00');
        $this->tenant = Tenant::factory()->create();
        $this->main = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main']);
        $this->branch = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Branch']);
        $this->tonic = Product::factory()->recycle($this->tenant)->create(['name' => 'Tonic', 'min_stock_cartons' => 10]);
    }

    public function test_each_batch_is_listed_with_its_cartons_value_and_whether_the_product_is_at_its_minimum(): void
    {
        $this->batch($this->main, $this->tonic, 'T-1', 4, 50);
        $this->batch($this->main, $this->tonic, 'T-2', 3, 60);
        $this->batch($this->branch, $this->tonic, 'T-3', 20, 50);
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole('Administrator');

        $response = $this->getJson('/api/v1/analytics/stock-snapshot')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertSame(['T-1', 'T-2', 'T-3'], $rows->sortBy('batch_no')->pluck('batch_no')->values()->all());
        $this->assertSame(true, $rows->firstWhere('batch_no', 'T-1')['at_or_below_minimum']);
        $this->assertSame('200.00', $rows->firstWhere('batch_no', 'T-1')['value']);
        $this->assertSame(false, $rows->firstWhere('batch_no', 'T-3')['at_or_below_minimum']);
        $response->assertJsonPath('report.totals.cartons', 27)
            ->assertJsonPath('report.totals.value', '1380.00')
            ->assertJsonPath('report.as_of', '2026-11-10');
    }

    public function test_the_report_says_who_produced_it_and_when(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $user = $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/stock-snapshot')
            ->assertOk()
            ->assertJsonPath('report.generated_by.id', $user->id)
            ->assertJsonPath('report.generated_at', '2026-11-10T10:00:00+00:00');
    }

    public function test_an_earlier_day_is_worked_out_from_the_stock_ledger(): void
    {
        $batch = $this->batch($this->main, $this->tonic, 'T-1', 10, 50);
        $this->travelTo('2026-11-05 09:00:00');
        $batch->decrement('qty_cartons', 4);
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/stock-snapshot?as_of=2026-10-15')->assertOk()->assertJsonPath('data.0.qty_cartons', 10)->assertJsonPath('report.as_of', '2026-10-15');
        $this->getJson('/api/v1/analytics/stock-snapshot?as_of=2026-11-05')->assertOk()->assertJsonPath('data.0.qty_cartons', 6);
        $this->getJson('/api/v1/analytics/stock-snapshot')->assertOk()->assertJsonPath('data.0.qty_cartons', 6);
        $this->getJson('/api/v1/analytics/stock-snapshot?as_of=2026-09-01')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_products_at_or_below_their_minimum_can_be_asked_for(): void
    {
        $this->batch($this->main, $this->tonic, 'T-1', 4, 50);
        $plenty = Product::factory()->recycle($this->tenant)->create(['min_stock_cartons' => 5]);
        $this->batch($this->main, $plenty, 'P-1', 50, 10);
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/stock-snapshot?below_minimum=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.batch_no', 'T-1');
    }

    public function test_a_warehouse_manager_sees_only_their_warehouse_and_no_cost(): void
    {
        $this->batch($this->main, $this->tonic, 'T-1', 4, 50);
        $this->batch($this->branch, $this->tonic, 'T-3', 20, 50);
        $this->travelTo('2026-11-10 10:00:00');
        $manager = $this->actingAsRole('Warehouse Manager');
        $this->main->users()->attach($manager);

        $response = $this->getJson('/api/v1/analytics/stock-snapshot')->assertOk()->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.batch_no', 'T-1')->assertJsonPath('data.0.value', null)->assertJsonPath('report.totals.value', null);
    }

    public function test_the_snapshot_can_be_exported_by_a_warehouse_manager(): void
    {
        $this->batch($this->main, $this->tonic, 'T-1', 4, 50);
        $manager = $this->actingAsRole('Warehouse Manager');
        $this->main->users()->attach($manager);

        $this->get('/api/v1/analytics/stock-snapshot?export=csv')->assertOk()->assertDownload();
    }

    public function test_another_companys_stock_is_not_included(): void
    {
        $foreign = Tenant::factory()->create();
        $this->batch(Warehouse::factory()->recycle($foreign)->create(), Product::factory()->recycle($foreign)->create(), 'X-1', 99, 1, $foreign);
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole('Administrator');

        $this->getJson('/api/v1/analytics/stock-snapshot')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_role_without_the_snapshot_right_is_forbidden(): void
    {
        $this->actingAsRole('Customer Service');

        $this->getJson('/api/v1/analytics/stock-snapshot')->assertForbidden();
    }

    private function batch(Warehouse $warehouse, Product $product, string $batchNo, int $cartons, float $cost, ?Tenant $tenant = null): InventoryBatch
    {
        return InventoryBatch::factory()->recycle([$tenant ?? $this->tenant, $product, $warehouse])->withQtyCartons($cartons)->create(['batch_no' => $batchNo, 'cost_per_carton' => $cost]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
