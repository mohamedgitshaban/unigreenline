<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductUnitService;
use Modules\Purchasing\Models\Supplier;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * A warehouse asking another warehouse, Purchasing or Manufacturing for stock.
 */
class StockRequestTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $branch;

    private Warehouse $main;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->branch = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Branch']);
        $this->main = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main']);
        $this->product = Product::factory()->recycle($this->tenant)->create();
        $this->ensureLedgerAccounts($this->tenant);
    }

    public function test_a_warehouse_employee_asks_another_warehouse_for_stock_and_its_manager_is_told(): void
    {
        $manager = $this->userIn($this->main, 'Warehouse Manager');
        $this->main->update(['manager_id' => $manager->id]);
        $employee = $this->userIn($this->branch, 'Warehouse Employee');

        $this->request($employee, ['notes' => 'Running low before the weekend'])
            ->assertCreated()
            ->assertJsonPath('data.number', 'SRQ-000001')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.warehouse.name', 'Branch')
            ->assertJsonPath('data.from_warehouse.name', 'Main')
            ->assertJsonPath('data.requested_by.id', $employee->id);

        $this->assertDatabaseHas('notifications', ['user_id' => $manager->id, 'type' => 'stock_request', 'unread' => 1]);
    }

    public function test_a_request_in_packs_asks_for_the_whole_cartons_it_rounds_up_to(): void
    {
        $packet = Unit::factory()->recycle($this->tenant)->create(['name' => 'Packet']);
        $package = Unit::factory()->recycle($this->tenant)->create(['name' => 'Package']);
        $carton = Unit::factory()->recycle($this->tenant)->create(['name' => 'Carton']);
        // 1 packet, 10 packets to a package, 3 packages (30 packets) to a carton.
        app(ProductUnitService::class)->sync($this->product, [['unit_id' => $packet->id], ['unit_id' => $package->id, 'contains' => 10], ['unit_id' => $carton->id, 'contains' => 3]]);

        $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['qty_cartons' => null, 'unit_id' => $packet->id, 'qty' => 45])
            ->assertCreated()
            ->assertJsonPath('data.qty_cartons', 2)
            ->assertJsonPath('data.unit_id', $packet->id)
            ->assertJsonPath('data.unit_qty', 45);
    }

    public function test_a_unit_that_is_not_one_of_the_products_units_is_refused(): void
    {
        $stranger = Unit::factory()->recycle($this->tenant)->create(['name' => 'Bottle']);

        $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['qty_cartons' => null, 'unit_id' => $stranger->id, 'qty' => 3])->assertUnprocessable();

        $this->assertDatabaseCount('stock_requests', 0);
    }

    public function test_a_request_needs_a_quantity_in_cartons_or_in_a_unit(): void
    {
        $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['qty_cartons' => null])->assertUnprocessable()->assertJsonValidationErrors('qty_cartons');
    }

    public function test_a_request_to_another_warehouse_needs_that_warehouse_and_a_different_one(): void
    {
        $employee = $this->userIn($this->branch, 'Warehouse Employee');

        $this->postJson('/api/v1/stock-requests', ['warehouse_id' => $this->branch->id, 'source' => 'warehouse', 'product_id' => $this->product->id, 'qty_cartons' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('from_warehouse_id');

        $this->postJson('/api/v1/stock-requests', ['warehouse_id' => $this->branch->id, 'source' => 'warehouse', 'from_warehouse_id' => $this->branch->id, 'product_id' => $this->product->id, 'qty_cartons' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('from_warehouse_id');
        $this->assertDatabaseCount('stock_requests', 0);
        $this->assertNotNull($employee);
    }

    public function test_a_user_can_only_ask_for_a_warehouse_they_work_in(): void
    {
        $this->userIn($this->main, 'Warehouse Employee');

        $this->postJson('/api/v1/stock-requests', ['warehouse_id' => $this->branch->id, 'source' => 'purchasing', 'product_id' => $this->product->id, 'qty_cartons' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_granting_a_warehouse_request_transfers_the_stock_earliest_expiry_first(): void
    {
        $soon = $this->stock($this->main, 'SOON', 3, '2027-01-31');
        $later = $this->stock($this->main, 'LATER', 10, '2028-01-31');
        $employee = $this->userIn($this->branch, 'Warehouse Employee');
        $id = $this->request($employee, ['qty_cartons' => 5])->json('data.id');

        $manager = $this->userIn($this->main, 'Warehouse Manager');
        $this->postJson("/api/v1/stock-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.result.type', 'transfer');

        $this->assertNull(InventoryBatch::query()->find($soon->id));
        $this->assertSame(8, $later->fresh()->qty_cartons);
        $this->assertSame(3, InventoryBatch::query()->where('warehouse_id', $this->branch->id)->where('batch_no', 'SOON')->value('qty_cartons'));
        $this->assertSame(2, InventoryBatch::query()->where('warehouse_id', $this->branch->id)->where('batch_no', 'LATER')->value('qty_cartons'));
        $this->assertDatabaseHas('notifications', ['user_id' => $employee->id, 'type' => 'stock_request']);
        $this->assertNotNull($manager);
    }

    public function test_granting_more_than_the_warehouse_has_is_refused_and_the_request_stays_open(): void
    {
        $this->stock($this->main, 'ONLY', 2, '2027-01-31');
        $id = $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['qty_cartons' => 5])->json('data.id');
        $this->userIn($this->main, 'Warehouse Manager');

        $this->postJson("/api/v1/stock-requests/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('shortfalls.0.needed', 5)
            ->assertJsonPath('shortfalls.0.available', 2);

        $this->assertSame('requested', StockRequest::query()->find($id)->status);
    }

    public function test_a_request_to_purchasing_becomes_a_draft_purchase_order_waiting_for_pricing(): void
    {
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->product->update(['supplier_id' => $supplier->id]);
        $id = $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['source' => 'purchasing', 'from_warehouse_id' => null, 'qty_cartons' => 7, 'needed_by' => '2026-12-01'])->json('data.id');

        $buyer = $this->userIn($this->main, 'Purchasing');
        $result = $this->postJson("/api/v1/stock-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.result.type', 'purchase_order')
            ->json('data.result');

        $this->assertDatabaseHas('purchase_orders', ['id' => $result['id'], 'supplier_id' => $supplier->id, 'warehouse_id' => $this->branch->id, 'status' => 'pending_pricing', 'created_by' => $buyer->id, 'expected_date' => '2026-12-01']);
        $this->assertDatabaseHas('purchase_order_lines', ['po_id' => $result['id'], 'product_id' => $this->product->id, 'qty_cartons' => 7]);
    }

    public function test_a_request_to_purchasing_for_a_product_without_a_supplier_asks_which_one(): void
    {
        $id = $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['source' => 'purchasing', 'from_warehouse_id' => null])->json('data.id');
        $this->userIn($this->main, 'Purchasing');

        $this->postJson("/api/v1/stock-requests/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->postJson("/api/v1/stock-requests/{$id}/approve", ['supplier_id' => $supplier->id])->assertOk()->assertJsonPath('data.status', 'fulfilled');
    }

    public function test_a_request_to_manufacturing_without_an_approved_recipe_asks_for_one(): void
    {
        $id = $this->request($this->userIn($this->branch, 'Warehouse Employee'), ['source' => 'manufacturing', 'from_warehouse_id' => null])->json('data.id');
        $this->userIn($this->main, 'Warehouse Manager');

        $this->postJson("/api/v1/stock-requests/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('bom_id');
        $this->assertSame('requested', StockRequest::query()->find($id)->status);
    }

    public function test_a_rejected_request_keeps_its_reason_and_tells_the_requester(): void
    {
        $employee = $this->userIn($this->branch, 'Warehouse Employee');
        $id = $this->request($employee)->json('data.id');
        $this->userIn($this->main, 'Warehouse Manager');

        $this->postJson("/api/v1/stock-requests/{$id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/stock-requests/{$id}/reject", ['reason' => 'Held for a customer order'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Held for a customer order');
        $this->assertTrue(Notification::query()->where('user_id', $employee->id)->where('body', 'like', '%Held for a customer order%')->exists());
    }

    public function test_the_requester_can_withdraw_an_open_request_but_not_a_decided_one(): void
    {
        $employee = $this->userIn($this->branch, 'Warehouse Employee');
        $id = $this->request($employee)->json('data.id');

        $this->postJson("/api/v1/stock-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/stock-requests/{$id}/cancel")->assertUnprocessable()->assertJsonPath('message', 'This request is already cancelled.');
    }

    public function test_an_employee_cannot_grant_a_request_and_a_stranger_cannot_see_it(): void
    {
        $employee = $this->userIn($this->branch, 'Warehouse Employee');
        $id = $this->request($employee)->json('data.id');

        $this->postJson("/api/v1/stock-requests/{$id}/approve")->assertForbidden();

        $outsider = Warehouse::factory()->recycle($this->tenant)->create();
        $this->userIn($outsider, 'Warehouse Manager');
        $this->getJson("/api/v1/stock-requests/{$id}")->assertForbidden();
        $this->getJson('/api/v1/stock-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_list_shows_requests_for_and_from_the_users_warehouses(): void
    {
        $this->request($this->userIn($this->branch, 'Warehouse Employee'));

        $this->userIn($this->main, 'Warehouse Manager');
        $this->getJson('/api/v1/stock-requests')->assertOk()->assertJsonCount(1, 'data');

        $this->userIn($this->branch, 'Warehouse Employee');
        $this->getJson('/api/v1/stock-requests?search=SRQ-000001')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_companys_request_is_not_found(): void
    {
        $id = $this->request($this->userIn($this->branch, 'Warehouse Employee'))->json('data.id');

        $stranger = User::factory()->create();
        $stranger->assignRole('Administrator');
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/stock-requests/{$id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(User $as, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/stock-requests', $overrides + [
            'warehouse_id' => $this->branch->id,
            'source' => 'warehouse',
            'from_warehouse_id' => $this->main->id,
            'product_id' => $this->product->id,
            'qty_cartons' => 2,
        ]);
    }

    private function userIn(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function stock(Warehouse $warehouse, string $batchNo, int $cartons, string $expires): InventoryBatch
    {
        return InventoryBatch::factory()->recycle([$this->tenant, $this->product, $warehouse])->withQtyCartons($cartons)->expiringOn($expires)->create(['batch_no' => $batchNo]);
    }
}
