<?php

namespace Modules\Purchasing\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\CreatePurchaseOrderService;
use Tests\TestCase;

class CreatePurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_line_and_order_totals_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $user = User::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create(['tax_pct' => 14]);

        $po = $this->service()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $user->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_cartons' => 10, 'cost_per_carton' => 90],
            ],
        ]);

        // line total = 10 * 90 = 900; tax = 900 * 0.14 = 126; PO total = 1026
        $this->assertSame('900.00', $po->subtotal);
        $this->assertSame('126.00', $po->tax_amount);
        $this->assertSame('1026.00', $po->total);
        $this->assertSame('900.00', $po->lines->first()->total);
    }

    public function test_records_an_audit_log_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $supplier = Supplier::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        $user = User::factory()->recycle($tenant)->create();
        $product = Product::factory()->recycle($tenant)->create();

        $po = $this->service()->create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $user->id,
            'lines' => [
                ['product_id' => $product->id, 'qty_cartons' => 5, 'cost_per_carton' => 50],
            ],
        ]);

        $this->assertDatabaseHas('audit_log', [
            'module' => 'purchasing',
            'entity_type' => 'PurchaseOrder',
            'entity_id' => $po->id,
            'operation' => 'INSERT',
        ]);
    }

    private function service(): CreatePurchaseOrderService
    {
        return $this->app->make(CreatePurchaseOrderService::class);
    }
}
