<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Modules\Core\Models\Notification;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\QualityCheckDefinition;
use Modules\Manufacturing\Tests\ManufacturingTestCase;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class SalesDemandAndNotificationsTest extends ManufacturingTestCase
{
    private $tonic;

    private $herb;

    private $bottle;

    private Bom $bom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tonic = $this->product('Tonic');
        $this->herb = $this->product('Herb', ['pack_cost_price' => 1]);
        $this->bottle = $this->product('Bottle', ['pack_cost_price' => 2]);
        $this->bom = $this->bom($this->tonic, [[$this->herb, 5], [$this->bottle, 3]], ['output_qty_cartons' => 10, 'conversion_cost' => 100]);
    }

    public function test_an_open_sales_order_raises_draft_orders_for_what_stock_does_not_cover(): void
    {
        $this->actingAsRole('Administrator');
        $this->stock($this->tonic, 5);
        $order = $this->salesOrder([[$this->tonic, 25]]);

        $response = $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertCreated()->assertJsonCount(1, 'data');

        $this->assertSame(20, $response->json('data.0.output_qty_cartons'));    // 25 wanted, 5 in stock
        $this->assertSame('draft', $response->json('data.0.status'));
        $this->assertSame('sales_order', $response->json('data.0.source_type'));
        $this->assertSame($order->id, $response->json('data.0.source_id'));
        $this->assertSame([], $response->json('skipped'));

        // It traces back to the demand, and is never raised twice for the same product.
        $this->getJson("/api/v1/production-orders?source_type=sales_order&source_id={$order->id}")->assertOk()->assertJsonCount(1, 'data');
        $again = $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertCreated();
        $this->assertSame([], $again->json('data'));
        $this->assertSame('already_planned', $again->json('skipped.0.reason'));
        $this->assertSame(1, ProductionOrder::query()->where('source_id', $order->id)->count());
    }

    public function test_products_with_no_recipe_or_enough_stock_are_skipped_and_gifts_ignored(): void
    {
        $this->actingAsRole('Administrator');
        $plain = $this->product('Plain');
        $this->stock($this->tonic, 30);
        $order = $this->salesOrder([[$this->tonic, 10], [$plain, 4]]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $this->herb->id, 'qty' => 9, 'unit' => 'Carton', 'free_qty' => 0, 'is_gift' => true]);

        $response = $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertCreated();

        $this->assertSame([], $response->json('data'));
        $reasons = collect($response->json('skipped'))->pluck('reason', 'product_id');
        $this->assertSame('covered_by_stock', $reasons[$this->tonic->id]);
        $this->assertSame('no_recipe', $reasons[$plain->id]);
        $this->assertArrayNotHasKey($this->herb->id, $reasons->all());
    }

    public function test_only_open_sales_orders_can_drive_production_and_other_companies_orders_are_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $delivered = $this->salesOrder([[$this->tonic, 5]], 'delivered');
        $foreign = SalesOrder::factory()->create();

        $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $delivered->id])->assertUnprocessable();
        $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $foreign->id])->assertNotFound();
        $this->postJson('/api/v1/production-orders/from-sales-order', [])->assertUnprocessable();
    }

    public function test_a_sales_manager_may_ask_for_production_but_a_warehouse_employee_may_not_read_sales(): void
    {
        $order = $this->salesOrder([[$this->tonic, 5]]);

        $this->actingAsRole('Sales Manager');
        $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertCreated();

        $this->actingAsRole('Warehouse Employee');   // may add production orders, but cannot see the sales order
        $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertForbidden();

        $this->actingAsRole('Sales Rep');
        $this->postJson('/api/v1/production-orders/from-sales-order', ['sales_order_id' => $order->id])->assertForbidden();
    }

    public function test_a_material_shortage_tells_the_creator_and_the_managers_of_production(): void
    {
        $manager = $this->actingAsRole('Warehouse Manager');
        $creator = $this->actingAsRole('Warehouse Employee');
        $id = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertForbidden();   // an employee cannot confirm...
        $this->actingAsRole('Administrator');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'none');

        $notified = Notification::query()->where('type', 'material_shortage')->pluck('user_id')->all();
        $this->assertContains($manager->id, $notified);
        $this->assertContains($creator->id, $notified);
        $this->assertSame(1, Notification::query()->where('type', 'material_shortage')->where('user_id', $creator->id)->count());
        $this->assertSame($id, Notification::query()->where('type', 'material_shortage')->first()->link_id);
        $this->assertSame('manufacturing', Notification::query()->where('type', 'material_shortage')->first()->link_module);
    }

    public function test_completing_and_cancelling_notify_the_creator_and_a_failed_quality_check_the_managers(): void
    {
        $this->stock($this->herb, 50, 10);
        $this->stock($this->bottle, 50, 20);
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Appearance', 'point' => 'final']);
        $manager = $this->actingAsRole('Warehouse Manager');
        $admin = $this->actingAsRole('Administrator');

        $done = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => now()->addYear()->toDateString()])->json('data.id');
        $this->postJson("/api/v1/production-orders/{$done}/confirm")->assertOk();
        $check = ProductionOrder::findOrFail($done)->qualityChecks()->sole();
        $this->postJson("/api/v1/quality-checks/{$check->id}/record", ['result' => 'failed'])->assertOk();

        $this->assertSame(1, Notification::query()->where('type', 'quality_failed')->where('user_id', $manager->id)->count());

        $retest = $this->postJson('/api/v1/quality-checks', ['definition_id' => $check->definition_id, 'production_order_id' => $done])->json('data.id');
        $this->postJson("/api/v1/quality-checks/{$retest}/record", ['result' => 'passed'])->assertOk();
        $this->postJson("/api/v1/production-orders/{$done}/complete")->assertOk();
        $this->assertSame(1, Notification::query()->where('type', 'production_completed')->where('user_id', $admin->id)->count());
        $this->assertSame(1, Notification::query()->where('type', 'goods_received')->where('user_id', $admin->id)->count());

        $cancelled = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10])->json('data.id');
        $this->postJson("/api/v1/production-orders/{$cancelled}/cancel", ['reason' => 'No demand'])->assertOk();
        $this->assertSame('No demand', Notification::query()->where('type', 'production_cancelled')->where('user_id', $admin->id)->value('body'));
    }

    public function test_nobody_is_told_about_something_that_was_rolled_back(): void
    {
        $this->actingAsRole('Administrator');
        $this->stock($this->herb, 50, 10);
        $this->stock($this->bottle, 1, 20);   // short: the receipt will fail
        $id = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => now()->addYear()->toDateString()])->json('data.id');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk();
        Notification::query()->delete();

        $this->postJson("/api/v1/production-orders/{$id}/record-production", ['qty' => 10])->assertUnprocessable();

        $this->assertSame(0, Notification::query()->whereIn('type', ['goods_received', 'production_completed'])->count());
    }

    public function test_a_finished_planning_run_notifies_the_managers(): void
    {
        $manager = $this->actingAsRole('Warehouse Manager');
        $this->actingAsRole('Administrator');
        $this->putJson('/api/v1/manufacturing/settings', ['default_warehouse_id' => $this->warehouse->id])->assertOk();

        $this->postJson('/api/v1/mrp/plan')->assertCreated();

        $this->assertSame(1, Notification::query()->where('type', 'mrp_completed')->where('user_id', $manager->id)->count());
    }

    /**
     * @param  array<int, array{0: mixed, 1: int}>  $lines
     */
    private function salesOrder(array $lines, string $status = 'draft'): SalesOrder
    {
        $customer = Customer::factory()->recycle($this->tenant)->create();
        $rep = User::factory()->recycle($this->tenant)->create();
        $order = SalesOrder::factory()->recycle([$this->tenant, $customer, $this->warehouse])->create([
            'sales_rep_id' => $rep->id, 'status' => $status, 'order_date' => now()->toDateString(), 'stock_deducted' => $status === 'delivered',
        ]);

        foreach ($lines as [$product, $qty]) {
            SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $product->id, 'qty' => $qty, 'unit' => 'Carton', 'free_qty' => 0, 'is_gift' => false]);
        }

        return $order;
    }
}
