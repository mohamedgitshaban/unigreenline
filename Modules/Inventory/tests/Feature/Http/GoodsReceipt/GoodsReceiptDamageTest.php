<?php

namespace Modules\Inventory\Tests\Feature\Http\GoodsReceipt;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Goods that arrive damaged, or have to be destroyed, are received with the rest and written off by a draft recap.
 */
class GoodsReceiptDamageTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->product = Product::factory()->recycle($this->tenant)->create();
        $this->ensureLedgerAccounts($this->tenant);

        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Warehouse Manager');
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);
    }

    public function test_damaged_and_destroyed_cartons_are_received_and_drafted_for_write_off(): void
    {
        $response = $this->receive(['damaged_cartons' => 2, 'destroyed_cartons' => 1])
            ->assertCreated()
            ->assertJsonPath('data.qty_cartons', 10)
            ->assertJsonCount(2, 'write_offs');

        $this->assertSame(10, InventoryBatch::query()->where('batch_no', 'DEL-1')->value('qty_cartons'));
        $this->assertDatabaseHas('recaps', ['id' => $response->json('write_offs.0'), 'reason' => 'damaged', 'status' => 'draft', 'warehouse_id' => $this->warehouse->id]);
        $this->assertDatabaseHas('recaps', ['id' => $response->json('write_offs.1'), 'reason' => 'other', 'status' => 'draft']);
        $this->assertDatabaseHas('recap_lines', ['recap_id' => $response->json('write_offs.0'), 'qty_cartons' => 2]);
        $this->assertDatabaseHas('recap_lines', ['recap_id' => $response->json('write_offs.1'), 'qty_cartons' => 1]);
    }

    public function test_only_one_draft_is_raised_when_only_one_kind_is_reported(): void
    {
        $this->receive(['damaged_cartons' => 3])->assertCreated()->assertJsonCount(1, 'write_offs');

        $this->assertDatabaseCount('recaps', 1);
    }

    public function test_a_clean_receipt_raises_no_write_off(): void
    {
        $this->receive()->assertCreated()->assertJsonCount(0, 'write_offs');

        $this->assertDatabaseCount('recaps', 0);
    }

    public function test_more_damaged_cartons_than_were_received_are_refused_and_nothing_is_received(): void
    {
        $this->receive(['damaged_cartons' => 8, 'destroyed_cartons' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['damaged_cartons' => 'More cartons are marked damaged or destroyed than were received.']);

        $this->assertDatabaseCount('inventory_batches', 0);
        $this->assertDatabaseCount('recaps', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function receive(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/inventory/grn', $overrides + [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_no' => 'DEL-1',
            'qty_cartons' => 10,
            'cost_per_carton' => 25,
            'exp_date' => '2028-03-31',
        ]);
    }
}
