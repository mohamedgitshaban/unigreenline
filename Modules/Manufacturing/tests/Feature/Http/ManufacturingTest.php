<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Tests\TestCase;

class ManufacturingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Product $finished;

    private Product $herb;

    private Product $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->finished = Product::factory()->recycle($this->tenant)->create();
        $this->herb = Product::factory()->recycle($this->tenant)->create();
        $this->bottle = Product::factory()->recycle($this->tenant)->create();
        Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset', 'balance' => 1000]);
        Account::factory()->recycle($this->tenant)->create(['code' => '6400', 'type' => 'Expense', 'balance' => 0]);
    }

    public function test_creates_edits_and_deletes_a_bom(): void
    {
        $this->actingAsRole('Administrator');

        $id = $this->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id,
            'name' => 'Herbal tonic',
            'output_qty_cartons' => 5,
            'conversion_cost' => 30,
            'lines' => [
                ['component_product_id' => $this->herb->id, 'qty_cartons' => 2],
                ['component_product_id' => $this->bottle->id, 'qty_cartons' => 5],
            ],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.lines')
            ->json('data.id');

        $this->putJson("/api/v1/boms/{$id}", ['name' => 'Herbal tonic v2', 'lines' => [['component_product_id' => $this->herb->id, 'qty_cartons' => 3]]])
            ->assertOk()
            ->assertJsonPath('data.name', 'Herbal tonic v2')
            ->assertJsonCount(1, 'data.lines');

        $this->deleteJson("/api/v1/boms/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('boms', ['id' => $id]);
        $this->assertDatabaseMissing('bom_lines', ['bom_id' => $id]);
    }

    public function test_a_product_cannot_be_its_own_component_and_components_cannot_repeat(): void
    {
        $this->actingAsRole('Administrator');

        $this->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id,
            'name' => 'Loop',
            'lines' => [['component_product_id' => $this->finished->id, 'qty_cartons' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.component_product_id');

        $this->postJson('/api/v1/boms', [
            'product_id' => $this->finished->id,
            'name' => 'Dup',
            'lines' => [
                ['component_product_id' => $this->herb->id, 'qty_cartons' => 1],
                ['component_product_id' => $this->herb->id, 'qty_cartons' => 2],
            ],
        ])->assertUnprocessable();
    }

    public function test_a_bom_with_production_orders_cannot_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $bom = $this->bom();
        ProductionOrder::factory()->recycle($this->tenant)->create(['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id]);

        $this->deleteJson("/api/v1/boms/{$bom->id}")->assertConflict();
        $this->assertDatabaseHas('boms', ['id' => $bom->id]);
    }

    public function test_completing_consumes_components_fefo_creates_the_batch_and_posts_the_cost(): void
    {
        $manager = $this->actingAsRole('Warehouse Manager');
        $bom = $this->bom(['output_qty_cartons' => 5, 'conversion_cost' => 30]);
        // Herb: 2 cartons per run, 3 runs = 6 cartons, drawn from the earlier-expiring batch first.
        $early = $this->batch($this->herb, ['qty_cartons' => 4, 'cost_per_carton' => '10.00', 'exp_date' => now()->addMonths(2)->toDateString()]);
        $late = $this->batch($this->herb, ['qty_cartons' => 10, 'cost_per_carton' => '20.00', 'exp_date' => now()->addMonths(6)->toDateString()]);
        $bottles = $this->batch($this->bottle, ['qty_cartons' => 20, 'cost_per_carton' => '1.00']);

        $orderId = $this->postJson('/api/v1/production-orders', [
            'bom_id' => $bom->id,
            'warehouse_id' => $this->warehouse->id,
            'runs' => 3,
            'batch_no' => 'TONIC-001',
            'exp_date' => now()->addYear()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.output_qty_cartons', 15)
            ->assertJsonPath('data.conversion_cost', '90.00')
            ->json('data.id');

        $this->postJson("/api/v1/production-orders/{$orderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_by.id', $manager->id)
            // 4×10 + 2×20 herb, 15×1 bottles = 95 materials; +90 conversion = 185 over 15 cartons.
            ->assertJsonPath('data.material_cost', '95.00')
            ->assertJsonPath('data.cost_per_carton', '12.33');

        $this->assertSame(0, $early->fresh()->qty_cartons);
        $this->assertSame(8, $late->fresh()->qty_cartons);
        $this->assertSame(5, $bottles->fresh()->qty_cartons);

        $output = InventoryBatch::query()->where('batch_no', 'TONIC-001')->firstOrFail();
        $this->assertSame($this->finished->id, $output->product_id);
        $this->assertSame(15, $output->qty_cartons);
        $this->assertSame('12.33', $output->cost_per_carton);

        $journalId = ProductionOrder::findOrFail($orderId)->journal_entry_id;
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_code' => '1300', 'debit' => '185.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_code' => '1300', 'credit' => '95.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_code' => '6400', 'credit' => '90.00']);
        // Inventory grows by the conversion cost; the overhead account absorbs it.
        $this->assertSame('1090.00', Account::query()->where('code', '1300')->first()->balance);
        $this->assertSame('-90.00', Account::query()->where('code', '6400')->first()->balance);
        $this->assertDatabaseHas('production_consumptions', ['production_order_id' => $orderId, 'batch_no' => $early->batch_no, 'qty_cartons' => 4]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'ProductionOrder', 'entity_id' => $orderId, 'operation' => 'APPROVE']);
    }

    public function test_a_component_shortfall_refuses_the_whole_order_and_changes_nothing(): void
    {
        $this->actingAsRole('Administrator');
        $bom = $this->bom();
        $herb = $this->batch($this->herb, ['qty_cartons' => 1]);
        $this->batch($this->bottle, ['qty_cartons' => 50]);
        $order = $this->order($bom, ['runs' => 2]);

        $this->postJson("/api/v1/production-orders/{$order->id}/complete")
            ->assertUnprocessable()
            ->assertJsonStructure(['shortfalls']);

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(1, $herb->fresh()->qty_cartons);
        $this->assertDatabaseMissing('inventory_batches', ['batch_no' => $order->batch_no]);
    }

    public function test_a_finished_batch_number_that_already_exists_is_refused(): void
    {
        $this->actingAsRole('Administrator');
        $bom = $this->bom();
        $this->batch($this->herb, ['qty_cartons' => 5]);
        $this->batch($this->bottle, ['qty_cartons' => 5]);
        $this->batch($this->finished, ['batch_no' => 'TAKEN-1']);
        $order = $this->order($bom, ['batch_no' => 'TAKEN-1']);

        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable();
    }

    public function test_drafts_can_be_edited_cancelled_and_deleted_but_completed_orders_cannot(): void
    {
        $this->actingAsRole('Administrator');
        $bom = $this->bom(['output_qty_cartons' => 4, 'conversion_cost' => 10]);
        $draft = $this->order($bom);

        $this->putJson("/api/v1/production-orders/{$draft->id}", ['runs' => 5])
            ->assertOk()
            ->assertJsonPath('data.output_qty_cartons', 20)
            ->assertJsonPath('data.conversion_cost', '50.00');

        $this->postJson("/api/v1/production-orders/{$draft->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->putJson("/api/v1/production-orders/{$draft->id}", ['runs' => 1])->assertUnprocessable();
        $this->deleteJson("/api/v1/production-orders/{$draft->id}")->assertNoContent();

        $done = ProductionOrder::factory()->recycle($this->tenant)->completed()->create(['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id]);
        $this->deleteJson("/api/v1/production-orders/{$done->id}")->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$done->id}/complete")->assertUnprocessable();
    }

    public function test_permissions_are_enforced_per_action(): void
    {
        $bom = $this->bom();
        $order = $this->order($bom);

        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/boms')->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertForbidden();
        $this->deleteJson("/api/v1/production-orders/{$order->id}")->assertForbidden();

        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/production-orders')->assertForbidden();
    }

    public function test_another_tenants_records_are_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $foreignBom = Bom::factory()->create();
        $foreignOrder = ProductionOrder::factory()->create();

        $this->getJson("/api/v1/boms/{$foreignBom->id}")->assertNotFound();
        $this->deleteJson("/api/v1/boms/{$foreignBom->id}")->assertNotFound();
        $this->postJson("/api/v1/production-orders/{$foreignOrder->id}/complete")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bom(array $attributes = []): Bom
    {
        $bom = Bom::factory()->recycle($this->tenant)->create(['product_id' => $this->finished->id] + $attributes);
        $bom->lines()->createMany([
            ['component_product_id' => $this->herb->id, 'qty_cartons' => 2],
            ['component_product_id' => $this->bottle->id, 'qty_cartons' => 5],
        ]);

        return $bom;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(Bom $bom, array $attributes = []): ProductionOrder
    {
        return ProductionOrder::factory()->recycle($this->tenant)->create([
            'bom_id' => $bom->id,
            'warehouse_id' => $this->warehouse->id,
            'output_qty_cartons' => $bom->output_qty_cartons * ($attributes['runs'] ?? 1),
        ] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function batch(Product $product, array $attributes = []): InventoryBatch
    {
        return InventoryBatch::factory()->recycle($this->tenant)->create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
        ] + $attributes);
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
