<?php

namespace Modules\Purchasing\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Exceptions\IncompleteReceiptException;
use Modules\Purchasing\Exceptions\PurchaseOrderAlreadyReceivedException;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderLine;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\ReceivePurchaseOrderService;
use Tests\TestCase;

class ReceivePurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_batch_for_the_line(): void
    {
        [$tenant, $po, $line, $product, $warehouse] = $this->arrange();

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->assertDatabaseHas('inventory_batches', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'BATCH-PO-1',
            'qty_cartons' => 10,
        ]);
    }

    public function test_tops_up_an_existing_batch_with_the_same_batch_number(): void
    {
        [$tenant, $po, $line, $product, $warehouse] = $this->arrange();
        $existing = InventoryBatch::factory()->recycle([$tenant, $product, $warehouse])
            ->create(['batch_no' => 'BATCH-PO-1', 'qty_cartons' => 5]);

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->assertSame(15, $existing->fresh()->qty_cartons);
    }

    public function test_marks_the_po_received_and_stock_added(): void
    {
        [$tenant, $po, $line] = $this->arrange();

        $updated = $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->assertSame('received', $updated->status);
        $this->assertTrue($updated->stock_added);
        $this->assertNotNull($updated->received_date);
    }

    public function test_decrements_the_suppliers_balance_by_the_po_total(): void
    {
        [$tenant, $po, $line, $product, $warehouse, $supplier] = $this->arrange();
        $supplier->forceFill(['balance' => 0])->save();

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->assertSame('-'.$po->total, $supplier->fresh()->balance);
    }

    public function test_posts_a_balanced_journal_entry(): void
    {
        [$tenant, $po, $line] = $this->arrange();

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $inventory = Account::query()->where('tenant_id', $tenant->id)->where('code', '1300')->first();
        $ap = Account::query()->where('tenant_id', $tenant->id)->where('code', '2100')->first();

        $this->assertSame((string) $po->total, $inventory->fresh()->balance);
        $this->assertSame((string) $po->total, $ap->fresh()->balance);
    }

    public function test_a_second_receive_attempt_is_rejected(): void
    {
        [$tenant, $po, $line] = $this->arrange();

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->expectException(PurchaseOrderAlreadyReceivedException::class);

        $this->service()->receive($po->fresh(), [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);
    }

    public function test_a_receipt_missing_a_line_is_rejected(): void
    {
        [$tenant, $po, $line, $product, $warehouse] = $this->arrange();
        $otherLine = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create(['product_id' => $product->id]);

        $this->expectException(IncompleteReceiptException::class);

        // Only one of the two lines is covered.
        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);
    }

    public function test_records_an_audit_log_entry(): void
    {
        [$tenant, $po, $line] = $this->arrange();

        $this->service()->receive($po, [
            ['line_id' => $line->id, 'batch_no' => 'BATCH-PO-1', 'exp_date' => '2027-12-31'],
        ]);

        $this->assertDatabaseHas('audit_log', [
            'module' => 'purchasing',
            'entity_type' => 'PurchaseOrder',
            'entity_id' => $po->id,
            'operation' => 'APPROVE',
        ]);
    }

    /**
     * @return array{0: Tenant, 1: PurchaseOrder, 2: PurchaseOrderLine, 3: Product, 4: Warehouse, 5: Supplier}
     */
    private function arrange(): array
    {
        $tenant = Tenant::factory()->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $po = PurchaseOrder::factory()->recycle([$tenant, $supplier, $warehouse])->create([
            'subtotal' => 1000, 'tax_amount' => 140, 'total' => 1140,
        ]);
        $line = PurchaseOrderLine::factory()->for($po, 'purchaseOrder')->create([
            'product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 100, 'total' => 1000,
        ]);

        // Every successful receive posts a journal entry.
        Account::factory()->recycle($tenant)->create(['code' => '1300', 'type' => 'Asset', 'balance' => 0]);
        Account::factory()->recycle($tenant)->create(['code' => '2100', 'type' => 'Liability', 'balance' => 0]);

        return [$tenant, $po, $line, $product, $warehouse, $supplier];
    }

    private function service(): ReceivePurchaseOrderService
    {
        return $this->app->make(ReceivePurchaseOrderService::class);
    }
}
