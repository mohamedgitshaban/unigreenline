<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Modules\Inventory\Services\FefoStockService;
use Modules\Inventory\Services\TransferStockService;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

class ProductionOrderLifecycleTest extends ManufacturingTestCase
{
    private Bom $bom;

    private $finished;

    private $herb;

    private $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finished = $this->product('Tonic', ['carton_qty' => 10, 'pack_cost_price' => 3]);
        $this->herb = $this->product('Herb', ['carton_qty' => 10, 'pack_cost_price' => 1]);     // 10 per carton
        $this->bottle = $this->product('Bottle', ['carton_qty' => 10, 'pack_cost_price' => 2]); // 20 per carton
        $this->bom = $this->bom($this->finished, [[$this->herb, 5, 10.0], [$this->bottle, 3]], ['output_qty_cartons' => 10, 'conversion_cost' => 100]);
    }

    public function test_creating_an_order_numbers_it_and_works_out_the_output_from_a_quantity_or_runs(): void
    {
        $this->actingAsRole('Administrator');

        $byQty = $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 25])
            ->assertCreated()
            ->assertJsonPath('data.number', 'MO00001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.output_qty_cartons', 25)
            ->assertJsonPath('data.runs', 3)
            ->assertJsonPath('data.conversion_cost', '250.00')
            ->assertJsonPath('data.remaining_qty_cartons', 25);

        $this->assertNull($byQty->json('data.exp_date'));

        $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'runs' => 2, 'exp_date' => now()->addYear()->toDateString()])
            ->assertCreated()->assertJsonPath('data.number', 'MO00002')->assertJsonPath('data.output_qty_cartons', 20);

        $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'runs' => 2, 'qty_cartons' => 5])->assertUnprocessable();
    }

    public function test_a_draft_bom_cannot_start_an_order(): void
    {
        $this->actingAsRole('Administrator');
        $this->bom->update(['status' => 'draft']);

        $this->postJson('/api/v1/production-orders', ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id])->assertUnprocessable();
    }

    public function test_confirming_fixes_components_estimates_cost_and_reserves_the_materials(): void
    {
        $this->actingAsRole('Administrator');
        $this->stock($this->herb, 20, 10);
        $this->stock($this->bottle, 20, 20);
        $order = $this->draft(['qty_cartons' => 10]);

        $response = $this->postJson("/api/v1/production-orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.reservation_status', 'reserved')
            ->assertJsonPath('data.bom.revision', 1)
            // 5.5 herb (5 + 10% scrap) -> 6 whole cartons; 3 bottles.
            ->assertJsonPath('data.costs.planned_material', '115.00');

        $components = collect($response->json('data.components'))->keyBy('product.name');
        $this->assertSame('5.5000', $components['Herb']['required_qty']);
        $this->assertSame(6, $components['Herb']['required_cartons']);
        $this->assertSame(6, $components['Herb']['reserved_cartons']);
        $this->assertSame(3, $components['Bottle']['required_cartons']);

        // Planned: herb 5.5 x 10 + bottle 3 x 20 = 115; overhead = the order's conversion cost.
        $this->assertSame('100.00', $response->json('data.costs.planned_overhead'));
        $this->assertSame(['create', 'confirm', 'reserve-materials'], $order->events()->pluck('action')->all());
    }

    public function test_the_status_cannot_be_set_directly_and_moves_must_follow_the_state_machine(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draft();

        $this->putJson("/api/v1/production-orders/{$order->id}", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'draft');

        // From draft you can confirm or cancel, not plan, start, pause or resume.
        foreach (['plan', 'start', 'pause', 'resume', 'reopen'] as $action) {
            $this->postJson("/api/v1/production-orders/{$order->id}/{$action}")->assertUnprocessable();
        }

        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/resume")->assertUnprocessable();

        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk()->assertJsonPath('data.status', 'planned');
        $this->postJson("/api/v1/production-orders/{$order->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/production-orders/{$order->id}/pause", ['reason' => 'Machine check'])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/production-orders/{$order->id}/pause")->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 1])->assertUnprocessable(); // paused
        $this->postJson("/api/v1/production-orders/{$order->id}/resume")->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(['create', 'confirm', 'reserve-materials', 'plan', 'start', 'pause', 'resume'], $order->events()->pluck('action')->all());
    }

    public function test_a_company_can_switch_a_transition_off_but_not_invent_one(): void
    {
        $this->actingAsRole('Administrator');

        $this->putJson('/api/v1/manufacturing/settings', ['allowed_transitions' => ['draft' => ['confirmed'], 'confirmed' => ['planned', 'in_progress', 'cancelled'], 'planned' => ['in_progress'], 'in_progress' => ['completed'], 'paused' => ['in_progress'], 'cancelled' => ['draft']]])->assertOk();
        $this->putJson('/api/v1/manufacturing/settings', ['allowed_transitions' => ['draft' => ['in_progress']]])->assertUnprocessable();

        $order = $this->draft();
        // Cancelling a draft was switched off in the settings above.
        $this->postJson("/api/v1/production-orders/{$order->id}/cancel")->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();
        // And pausing from in progress too.
        $this->postJson("/api/v1/production-orders/{$order->id}/start")->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/pause")->assertUnprocessable();
    }

    public function test_changing_the_bom_after_confirming_does_not_move_the_orders_requirements(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draft(['qty_cartons' => 10]);
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();

        $this->putJson("/api/v1/boms/{$this->bom->id}", ['lines' => [['component_product_id' => $this->herb->id, 'qty_cartons' => 50]]])->assertOk()->assertJsonPath('data.version', 2);

        $components = collect($this->getJson("/api/v1/production-orders/{$order->id}")->assertOk()->json('data.components'))->keyBy('product.name');
        $this->assertSame(6, $components['Herb']['required_cartons']);
        $this->assertSame(3, $components['Bottle']['required_cartons']);
        $this->getJson("/api/v1/production-orders/{$order->id}")->assertJsonPath('data.bom.revision', 1);
    }

    public function test_after_confirming_only_planning_details_may_be_edited(): void
    {
        $user = $this->actingAsRole('Administrator');
        $order = $this->draft();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();

        $this->putJson("/api/v1/production-orders/{$order->id}", ['priority' => 3, 'assigned_to' => $user->id, 'notes' => 'Rush'])
            ->assertOk()->assertJsonPath('data.priority', 3)->assertJsonPath('data.assigned_to.id', $user->id);
        $this->putJson("/api/v1/production-orders/{$order->id}", ['qty_cartons' => 99])->assertUnprocessable();
        $this->deleteJson("/api/v1/production-orders/{$order->id}")->assertUnprocessable();
    }

    public function test_reserved_stock_cannot_be_taken_by_sales_transfers_or_write_offs(): void
    {
        $this->actingAsRole('Administrator');
        $herbBatch = $this->stock($this->herb, 10, 10);
        $this->stock($this->bottle, 20, 20);
        $order = $this->draft(['qty_cartons' => 10]);
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'reserved');

        $this->assertSame(6, $herbBatch->fresh()->reserved_cartons);

        // 10 on hand, 6 reserved: only 4 are available to anyone else.
        $fefo = app(FefoStockService::class);
        $this->assertSame([], $fefo->checkAvailability([['product_id' => $this->herb->id, 'warehouse_id' => $this->warehouse->id, 'cartons_needed' => 4]]));
        $short = $fefo->checkAvailability([['product_id' => $this->herb->id, 'warehouse_id' => $this->warehouse->id, 'cartons_needed' => 5]]);
        $this->assertSame(4, $short[0]['available']);

        $other = Warehouse::factory()->recycle($this->tenant)->create();
        $this->expectException(\Modules\Inventory\Exceptions\InsufficientStockException::class);
        app(TransferStockService::class)->transfer([
            'tenant_id' => $this->tenant->id, 'product_id' => $this->herb->id, 'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $other->id, 'batch_no' => $herbBatch->batch_no, 'qty_cartons' => 5,
        ]);
    }

    public function test_a_second_order_cannot_reserve_what_the_first_holds(): void
    {
        $this->actingAsRole('Administrator');
        $herbBatch = $this->stock($this->herb, 8, 10);
        $this->stock($this->bottle, 20, 20);

        $first = $this->draft(['qty_cartons' => 10]);
        $second = $this->draft(['qty_cartons' => 10]);

        $this->postJson("/api/v1/production-orders/{$first->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'reserved');
        // 8 on hand, 6 reserved by the first: the second gets 2 of the 6 it needs.
        $this->postJson("/api/v1/production-orders/{$second->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'partial');

        $this->assertSame(8, $herbBatch->fresh()->reserved_cartons);

        $reserve = $this->postJson("/api/v1/production-orders/{$second->id}/reserve-materials")->assertOk();
        $this->assertSame('Herb', collect($reserve->json('data.components'))->firstWhere('reserved_cartons', 2)['product']['name']);
        $this->assertSame(4, $reserve->json('shortages.0.short'));

        // Giving the first order's reservation back lets the second finish reserving.
        $this->postJson("/api/v1/production-orders/{$first->id}/release-materials")->assertOk()->assertJsonPath('data.reservation_status', 'none');
        $this->postJson("/api/v1/production-orders/{$second->id}/reserve-materials")->assertOk()->assertJsonPath('data.reservation_status', 'reserved');
        $this->assertSame(6, $herbBatch->fresh()->reserved_cartons);
    }

    public function test_manual_reservation_policy_leaves_stock_free_until_asked(): void
    {
        $this->actingAsRole('Administrator');
        $this->putJson('/api/v1/manufacturing/settings', ['reservation_policy' => 'manual'])->assertOk();
        $batch = $this->stock($this->herb, 20, 10);
        $this->stock($this->bottle, 20, 20);
        $order = $this->draft();

        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'none');
        $this->assertSame(0, $batch->fresh()->reserved_cartons);

        $this->postJson("/api/v1/production-orders/{$order->id}/reserve-materials")->assertOk()->assertJsonPath('data.reservation_status', 'reserved');
        $this->assertGreaterThan(0, $batch->fresh()->reserved_cartons);
    }

    public function test_expired_stock_is_never_reserved(): void
    {
        $this->actingAsRole('Administrator');
        $this->stock($this->herb, 20, 10, ['exp_date' => now()->subDay()->toDateString()]);
        $this->stock($this->bottle, 20, 20);
        $order = $this->draft();

        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'partial');
    }

    public function test_cancelling_releases_the_reservation_and_is_refused_once_stock_has_moved(): void
    {
        $this->actingAsRole('Administrator');
        $batch = $this->stock($this->herb, 20, 10);
        $this->stock($this->bottle, 20, 20);

        $order = $this->draft();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();
        $this->assertGreaterThan(0, $batch->fresh()->reserved_cartons);

        $this->postJson("/api/v1/production-orders/{$order->id}/cancel", ['reason' => 'Customer withdrew'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Customer withdrew')->assertJsonPath('data.reservation_status', 'none');
        $this->assertSame(0, $batch->fresh()->reserved_cartons);

        $started = $this->draft();
        $this->postJson("/api/v1/production-orders/{$started->id}/confirm")->assertOk();
        $this->postJson("/api/v1/production-orders/{$started->id}/record-production", ['qty' => 2, 'exp_date' => now()->addYear()->toDateString()])->assertOk();
        $this->postJson("/api/v1/production-orders/{$started->id}/cancel")->assertUnprocessable();
    }

    public function test_a_cancelled_order_can_be_reopened_only_when_the_company_allows_it(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draft();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/cancel")->assertOk();

        $this->postJson("/api/v1/production-orders/{$order->id}/reopen")->assertUnprocessable();

        $this->putJson('/api/v1/manufacturing/settings', ['allow_reopen_cancelled' => true])->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/reopen")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonCount(0, 'data.components');

        // And it can be confirmed again.
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();
        $this->assertContains('reopen', $order->events()->pluck('action')->all());
    }

    public function test_the_history_is_append_only(): void
    {
        $this->actingAsRole('Administrator');
        $order = $this->draft();
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk();

        $this->getJson("/api/v1/production-orders/{$order->id}/history")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.1.action', 'confirm')->assertJsonPath('data.1.to_status', 'confirmed');

        $event = $order->events()->first();
        $this->expectException(\LogicException::class);
        $event->update(['action' => 'rewritten']);
    }

    public function test_the_list_can_be_filtered_by_status_and_priority(): void
    {
        $this->actingAsRole('Administrator');
        $a = $this->draft(['priority' => 3]);
        $this->draft(['priority' => 0]);
        $this->postJson("/api/v1/production-orders/{$a->id}/confirm")->assertOk();

        $this->getJson('/api/v1/production-orders?status=confirmed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/production-orders?priority=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/production-orders?product_id='.$this->finished->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/production-orders?search=MO00001')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = []): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', $attributes + ['bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10])
            ->assertCreated()->json('data.id');

        return ProductionOrder::findOrFail($id);
    }
}
