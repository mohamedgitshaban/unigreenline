<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\StockMovementContext;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Modules\Recap\Models\Recap;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

/**
 * Every change to a batch's quantity is a line of the stock ledger, with the reason and the document behind it.
 */
class StockLedgerTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Warehouse $main;

    private Warehouse $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->admin = $this->user('Administrator');
        $this->main = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Main']);
        $this->branch = Warehouse::factory()->recycle($this->tenant)->create(['name' => 'Branch']);
        $this->product = Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin']);
        Sanctum::actingAs($this->admin);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function movements(?string $type = null)
    {
        return StockMovement::query()->where('tenant_id', $this->tenant->id)->when($type, fn ($q) => $q->where('type', $type))->orderBy('id')->get();
    }

    /** Every batch holds exactly what its movements add up to. */
    private function assertLedgerBalances(): void
    {
        $sums = DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->selectRaw('batch_id, sum(qty_cartons) as total')->groupBy('batch_id')->pluck('total', 'batch_id');

        foreach (InventoryBatch::query()->where('tenant_id', $this->tenant->id)->get() as $batch) {
            $this->assertSame($batch->qty_cartons, (int) ($sums[$batch->id] ?? 0), "batch {$batch->batch_no} does not match its ledger");
        }

        foreach ($sums as $batchId => $total) {
            if (! InventoryBatch::query()->whereKey($batchId)->exists()) {
                $this->assertSame(0, (int) $total, 'a batch that no longer exists must net to nothing');
            }
        }
    }

    public function test_a_manual_receipt_is_a_movement_in(): void
    {
        $this->postJson('/api/v1/inventory/grn', [
            'product_id' => $this->product->id, 'warehouse_id' => $this->main->id, 'batch_no' => 'B-1', 'qty_cartons' => 40,
            'cost_per_carton' => 12.5, 'exp_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $movement = $this->movements('goods_receipt')->sole();
        $this->assertSame(40, $movement->qty_cartons);
        $this->assertSame($this->main->id, $movement->warehouse_id);
        $this->assertSame($this->admin->id, $movement->user_id);
        $this->assertSame('B-1', $movement->batch_no);
        $this->assertEquals(12.5, (float) $movement->unit_cost);
        $this->assertLedgerBalances();
    }

    public function test_a_transfer_is_one_movement_out_and_one_in_pointing_at_each_other(): void
    {
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-2', 'qty_cartons' => 30]);

        $transferId = $this->postJson('/api/v1/transfers', [
            'product_id' => $this->product->id, 'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->branch->id, 'batch_no' => 'B-2', 'qty_cartons' => 12,
        ])->assertCreated()->json('data.id');

        $out = $this->movements('transfer_out')->sole();
        $in = $this->movements('transfer_in')->sole();

        $this->assertSame(-12, $out->qty_cartons);
        $this->assertSame($this->main->id, $out->warehouse_id);
        $this->assertSame($this->branch->id, $out->counterpart_warehouse_id);
        $this->assertSame(12, $in->qty_cartons);
        $this->assertSame($this->branch->id, $in->warehouse_id);
        $this->assertSame($this->main->id, $in->counterpart_warehouse_id);
        $this->assertSame(['transfer', $transferId], [$out->reference_type, $out->reference_id]);
        $this->assertSame(['transfer', $transferId], [$in->reference_type, $in->reference_id]);
        $this->assertLedgerBalances();
    }

    public function test_emptying_a_batch_by_transfer_still_balances(): void
    {
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-3', 'qty_cartons' => 10]);

        $this->postJson('/api/v1/transfers', [
            'product_id' => $this->product->id, 'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->branch->id, 'batch_no' => 'B-3', 'qty_cartons' => 10,
        ])->assertCreated();

        $this->assertDatabaseMissing('inventory_batches', ['warehouse_id' => $this->main->id, 'batch_no' => 'B-3']);
        $this->assertLedgerBalances();
    }

    public function test_receiving_a_purchase_order_records_the_order_as_the_reason(): void
    {
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        $po = PurchaseOrder::factory()->recycle([$this->tenant, $supplier, $this->main])->create(['subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000]);
        $line = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create(['product_id' => $this->product->id, 'qty_cartons' => 10, 'cost_per_carton' => 100, 'total' => 1000]);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'receipts' => [['line_id' => $line->id, 'batch_no' => 'B-PO', 'exp_date' => now()->addYear()->toDateString()]],
        ])->assertOk();

        $movement = $this->movements('purchase_receipt')->sole();
        $this->assertSame(10, $movement->qty_cartons);
        $this->assertSame(['purchase_order', $po->id], [$movement->reference_type, $movement->reference_id]);
        $this->assertSame($this->main->id, $movement->warehouse_id);
        $this->assertLedgerBalances();
    }

    public function test_selling_records_the_sales_order_as_the_reason(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create();
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-4', 'qty_cartons' => 50]);
        $order = SalesOrder::factory()->recycle([$this->tenant, $customer, $this->main])->create(['status' => 'draft', 'sales_rep_id' => $this->admin->id, 'subtotal' => 100, 'tax_amount' => 0, 'total' => 100]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $this->product->id, 'qty' => 5, 'unit' => 'Carton', 'free_qty' => 0]);
        Account::query()->where('tenant_id', $this->tenant->id)->exists() || $this->fail('ledger accounts missing');

        $this->putJson("/api/v1/sales-orders/{$order->id}/status", ['status' => 'invoiced'])->assertOk();

        $movement = $this->movements('sale')->sole();
        $this->assertSame(-5, $movement->qty_cartons);
        $this->assertSame(['sales_order', $order->id], [$movement->reference_type, $movement->reference_id]);
        $this->assertLedgerBalances();
    }

    public function test_approving_a_write_off_records_it(): void
    {
        $batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-5', 'qty_cartons' => 20, 'exp_date' => now()->subDay()->toDateString()]);

        $recapId = $this->postJson('/api/v1/recaps', ['warehouse_id' => $this->main->id, 'reason' => 'expired', 'lines' => [['batch_id' => $batch->id, 'qty_cartons' => 20]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/recaps/{$recapId}/approve")->assertOk();

        $movement = $this->movements('write_off')->sole();
        $this->assertSame(-20, $movement->qty_cartons);
        $this->assertSame(['recap', $recapId], [$movement->reference_type, $movement->reference_id]);
        $this->assertSame('expired', $movement->notes);
        $this->assertNotNull(Recap::query()->find($recapId));
        $this->assertLedgerBalances();
    }

    public function test_a_change_made_with_no_stated_reason_is_an_adjustment(): void
    {
        $batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-6', 'qty_cartons' => 10]);
        $batch->decrement('qty_cartons', 3);

        $this->assertSame([10, -3], $this->movements('adjustment')->pluck('qty_cartons')->all());
        $this->assertLedgerBalances();
    }

    public function test_a_stated_reason_applies_to_everything_inside_it_and_nesting_overrides(): void
    {
        $batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-7', 'qty_cartons' => 10]);

        StockMovementContext::run(['type' => 'sale', 'reference_type' => 'sales_order', 'reference_id' => 'SO-1'], function () use ($batch) {
            $batch->decrement('qty_cartons', 2);
            StockMovementContext::run(['type' => 'write_off'], fn () => $batch->decrement('qty_cartons', 1));
        });
        $batch->decrement('qty_cartons', 1);

        $this->assertSame([-2], $this->movements('sale')->pluck('qty_cartons')->all());
        $this->assertSame('SO-1', $this->movements('sale')->first()->reference_id);
        $this->assertSame([-1], $this->movements('write_off')->pluck('qty_cartons')->all());
        $this->assertSame('SO-1', $this->movements('write_off')->first()->reference_id, 'the outer reference carries into the inner reason');
        $this->assertSame(-1, $this->movements('adjustment')->last()->qty_cartons, 'the reason does not leak out');
    }

    public function test_the_ledger_of_a_warehouse_can_be_filtered_and_summed(): void
    {
        $other = Product::factory()->recycle($this->tenant)->create();
        $batch = InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-8', 'qty_cartons' => 100]);
        StockMovementContext::run(['type' => 'sale'], fn () => $batch->decrement('qty_cartons', 30));
        InventoryBatch::factory()->recycle([$this->tenant, $other, $this->main])->create(['batch_no' => 'B-9', 'qty_cartons' => 7]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->branch])->create(['batch_no' => 'B-10', 'qty_cartons' => 999]);

        $all = $this->getJson("/api/v1/warehouses/{$this->main->id}/movements")->assertOk();
        $all->assertJsonCount(3, 'data')->assertJsonPath('summary.total_in', 107)->assertJsonPath('summary.total_out', 30)->assertJsonPath('summary.net', 77);

        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?direction=out")->assertJsonCount(1, 'data')->assertJsonPath('data.0.type_label', 'Sale');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?product_id={$other->id}")->assertJsonCount(1, 'data')->assertJsonPath('summary.net', 7);
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?type=sale,adjustment")->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?batch_no=B-9")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?from=".now()->addDay()->toDateString())->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?search=Ivermectin")->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements?direction=sideways")->assertUnprocessable();
    }

    public function test_a_product_ledger_covers_the_warehouses_the_user_can_see(): void
    {
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-11', 'qty_cartons' => 5]);
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->branch])->create(['batch_no' => 'B-12', 'qty_cartons' => 6]);

        $this->getJson("/api/v1/products/{$this->product->id}/movements")->assertOk()->assertJsonCount(2, 'data');

        $storeman = $this->user('Warehouse Manager');
        $this->main->users()->attach($storeman);
        Sanctum::actingAs($storeman);

        $ledger = $this->getJson("/api/v1/products/{$this->product->id}/movements")->assertOk();
        $ledger->assertJsonCount(1, 'data')->assertJsonPath('data.0.warehouse.id', $this->main->id)->assertJsonPath('summary.net', 5);
    }

    public function test_the_ledger_hides_costs_from_everyone_but_the_administrator(): void
    {
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-13', 'qty_cartons' => 5, 'cost_per_carton' => 42]);

        $this->assertEquals(42, $this->getJson("/api/v1/warehouses/{$this->main->id}/movements")->json('data.0.unit_cost'));

        $storeman = $this->user('Warehouse Manager');
        $this->main->users()->attach($storeman);
        Sanctum::actingAs($storeman);

        $this->assertArrayNotHasKey('unit_cost', $this->getJson("/api/v1/warehouses/{$this->main->id}/movements")->json('data.0'));
    }

    public function test_a_warehouse_the_user_cannot_see_has_no_ledger_for_them_and_another_companys_is_not_found(): void
    {
        $storeman = $this->user('Warehouse Manager');
        $this->branch->users()->attach($storeman);
        Sanctum::actingAs($storeman);

        $this->getJson("/api/v1/warehouses/{$this->main->id}/movements")->assertForbidden();
        $this->getJson('/api/v1/warehouses/'.Warehouse::factory()->create()->id.'/movements')->assertNotFound();
        $this->getJson('/api/v1/products/'.Product::factory()->create()->id.'/movements')->assertNotFound();
    }

    public function test_movements_can_be_exported(): void
    {
        InventoryBatch::factory()->recycle([$this->tenant, $this->product, $this->main])->create(['batch_no' => 'B-14', 'qty_cartons' => 5]);

        $this->get("/api/v1/warehouses/{$this->main->id}/movements?export=csv")->assertOk()->assertDownload();
    }
}
