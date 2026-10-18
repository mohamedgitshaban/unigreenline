<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Manufacturing\Jobs\RunMrpPlanningJob;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\MrpDemand;
use Modules\Manufacturing\Models\MrpRun;
use Modules\Manufacturing\Models\MrpSuggestion;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductPlanning;
use Modules\Manufacturing\Services\MrpPlanner;
use Modules\Manufacturing\Services\MrpRunService;
use Modules\Manufacturing\Tests\ManufacturingTestCase;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Worked example: one run of the Tonic recipe makes 10 cartons from 5 cartons of Herb and 3 of
 * Bottle. So 15 cartons of Tonic is 1.5 runs: 7.5 Herb and 4.5 Bottle, bought in whole cartons.
 */
class MrpPlanningTest extends ManufacturingTestCase
{
    private $tonic;

    private $herb;

    private $bottle;

    private Bom $bom;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->tonic = $this->product('Tonic');
        $this->herb = $this->product('Herb', ['supplier_id' => $this->supplier->id]);
        $this->bottle = $this->product('Bottle', ['supplier_id' => $this->supplier->id]);
        $this->bom = $this->bom($this->tonic, [[$this->herb, 5], [$this->bottle, 3]], ['output_qty_cartons' => 10]);
        $this->actingAsRole('Administrator');
        $this->putJson('/api/v1/manufacturing/settings', ['default_warehouse_id' => $this->warehouse->id, 'lead_time_days' => 2])->assertOk();
    }

    public function test_a_shortage_of_a_made_product_becomes_a_manufacture_suggestion_and_demand_for_its_components(): void
    {
        $this->demand($this->tonic, 20, 10);
        $this->stock($this->tonic, 5);
        $this->stock($this->herb, 2);

        $run = $this->plan();

        $this->assertSame('completed', $run['status']);

        $make = MrpSuggestion::query()->where('type', 'manufacture')->sole();
        // 20 wanted, 5 in stock: make 15, due when it is needed, starting the 2-day lead time before.
        $this->assertSame(15, $make->qty_cartons);
        $this->assertSame(now()->addDays(10)->toDateString(), $make->due_date->toDateString());
        $this->assertSame(now()->addDays(8)->toDateString(), $make->start_date->toDateString());
        $this->assertSame('proposed', $make->status);

        // 15 cartons is 1.5 runs: Herb 7.5, less 2 in stock = 5.5 -> 6 bought; Bottle 4.5 -> 5 bought.
        $buys = MrpSuggestion::query()->where('type', 'purchase')->get()->keyBy('product_id');
        $this->assertSame(6, $buys[$this->herb->id]->qty_cartons);
        $this->assertSame(5, $buys[$this->bottle->id]->qty_cartons);
        // The components are needed when production starts.
        $this->assertSame($make->start_date->toDateString(), $buys[$this->herb->id]->due_date->toDateString());
        $this->assertSame($this->supplier->id, $buys[$this->herb->id]->supplier_id);
        $this->assertSame(now()->addDays(8)->toDateString(), $buys[$this->herb->id]->due_date->toDateString());
    }

    public function test_the_requirements_report_shows_demand_stock_supply_and_the_proposal_per_level(): void
    {
        $this->demand($this->tonic, 20, 10);
        $this->stock($this->tonic, 5);
        $this->stock($this->herb, 6, 10);
        $this->plan();

        $lines = collect($this->getJson('/api/v1/mrp/requirements')->assertOk()->json('data'))->keyBy('product.name');

        $tonic = $lines['Tonic'];
        $this->assertSame(0, $tonic['level']);
        $this->assertEquals(20, $tonic['gross_requirement']);
        $this->assertSame(5, $tonic['on_hand']);
        $this->assertEquals(15, $tonic['net_requirement']);
        $this->assertSame(15, $tonic['planned_qty']);
        $this->assertSame('manufacture', $tonic['action']);

        $herb = $lines['Herb'];
        $this->assertSame(1, $herb['level']);
        $this->assertEquals(7.5, $herb['gross_requirement']);
        $this->assertSame(6, $herb['on_hand']);
        $this->assertSame(2, $herb['planned_qty']);      // 7.5 - 6 = 1.5 -> 2
        $this->assertSame('purchase', $herb['action']);

        $this->getJson('/api/v1/mrp/requirements?level=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/mrp/requirements?action=manufacture')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/mrp/shortages')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.suggestions.0.type', 'manufacture');
    }

    public function test_stock_that_other_orders_have_reserved_is_not_counted_as_available(): void
    {
        $this->demand($this->herb, 8, 3);
        $batch = $this->stock($this->herb, 10);
        InventoryBatch::query()->whereKey($batch->id)->update(['reserved_cartons' => 4]);

        $this->plan();

        // 10 on hand but 4 reserved for someone else: 6 free, 8 wanted.
        $this->assertSame(2, MrpSuggestion::query()->where('product_id', $this->herb->id)->sole()->qty_cartons);
    }

    public function test_expired_stock_does_not_count(): void
    {
        $this->demand($this->herb, 8, 3);
        $this->stock($this->herb, 10, 10, ['exp_date' => now()->subDay()->toDateString()]);

        $this->plan();

        $this->assertSame(8, MrpSuggestion::query()->where('product_id', $this->herb->id)->sole()->qty_cartons);
    }

    public function test_scrap_allowance_is_part_of_the_requirement(): void
    {
        $this->bom->lines()->where('component_product_id', $this->herb->id)->update(['scrap_pct' => 10]);
        $this->demand($this->tonic, 10, 5);

        $this->plan();

        // One run: Herb 5 + 10% = 5.5 -> 6; Bottle 3.
        $buys = MrpSuggestion::query()->where('type', 'purchase')->get()->keyBy('product_id');
        $this->assertSame(6, $buys[$this->herb->id]->qty_cartons);
        $this->assertSame(3, $buys[$this->bottle->id]->qty_cartons);
    }

    public function test_incoming_purchase_orders_and_open_manufacturing_orders_count_as_supply(): void
    {
        $this->demand($this->tonic, 20, 10);
        // 12 Tonic is already on a (draft) manufacturing order starting on day 5...
        $this->makeOrder($this->bom, 12, ['planned_start' => now()->addDays(5)->toDateString()]);
        // ...and 4 Herb and 3 Bottle are already on order from the supplier.
        $this->purchaseOrder($this->herb, 4, 'sent');
        $this->purchaseOrder($this->bottle, 3, 'pending_pricing');

        $this->plan();

        // 20 wanted, 12 coming: make 8 more.
        $this->assertSame(8, MrpSuggestion::query()->where('type', 'manufacture')->sole()->qty_cartons);

        // Materials: the existing 12 need 1.2 runs (6.0 Herb, 3.6 Bottle) and the new 8 need 0.8 run (4.0 Herb, 2.4 Bottle):
        // 10.0 Herb and 6.0 Bottle in all. The purchase orders land on day 3, before either need, so 4 Herb and 3 Bottle
        // are covered and 6 Herb and 3 Bottle are still to buy.
        // (Two dated orders each: what the first order needs on day 5, and what the new one needs on day 8.)
        $bought = MrpSuggestion::query()->where('type', 'purchase')->get()->groupBy('product_id')->map->sum('qty_cartons');
        $this->assertSame(6, (int) $bought[$this->herb->id]);
        $this->assertSame(3, (int) $bought[$this->bottle->id]);
    }

    public function test_materials_an_open_order_has_already_reserved_are_not_needed_twice(): void
    {
        $this->stock($this->herb, 10);
        $this->stock($this->bottle, 10);
        $order = $this->makeOrder($this->bom, 10);
        $this->postJson("/api/v1/production-orders/{$order->id}/confirm")->assertOk()->assertJsonPath('data.reservation_status', 'reserved');

        $this->plan();

        // Everything the order needs is set aside, so there is nothing to buy.
        $this->assertSame(0, MrpSuggestion::query()->count());
    }

    public function test_multi_level_products_are_planned_from_the_top_down(): void
    {
        $syrup = $this->product('Syrup');
        $this->bom($syrup, [[$this->herb, 2]], ['output_qty_cartons' => 5]);
        $this->bom->lines()->where('component_product_id', $this->herb->id)->delete();
        $this->bom->lines()->create(['component_product_id' => $syrup->id, 'qty_cartons' => 4]);

        $this->demand($this->tonic, 10, 20);
        $this->plan();

        $lines = collect($this->getJson('/api/v1/mrp/requirements')->assertOk()->json('data'))->keyBy('product.name');
        $this->assertSame(0, $lines['Tonic']['level']);
        $this->assertSame(1, $lines['Syrup']['level']);
        $this->assertSame(2, $lines['Herb']['level']);

        // 10 Tonic = 1 run = 4 Syrup (make 4) = 0.8 run of syrup = 1.6 Herb -> buy 2; plus 3 Bottle.
        $syrupMake = MrpSuggestion::query()->where('product_id', $syrup->id)->sole();
        $this->assertSame('manufacture', $syrupMake->type);
        $this->assertSame(4, $syrupMake->qty_cartons);
        $this->assertSame(2, MrpSuggestion::query()->where('product_id', $this->herb->id)->sole()->qty_cartons);
        // Syrup is needed when the Tonic starts (due - 2 days); its own start is 2 days before that.
        $tonicMake = MrpSuggestion::query()->where('product_id', $this->tonic->id)->sole();
        $this->assertSame($tonicMake->start_date->toDateString(), $syrupMake->due_date->toDateString());
        $this->assertSame($syrupMake->start_date->copy()->addDays(2)->toDateString(), $syrupMake->due_date->toDateString());
    }

    public function test_lot_rules_size_the_orders(): void
    {
        ProductPlanning::factory()->recycle($this->tenant)->create(['product_id' => $this->herb->id, 'lot_rule' => 'min_max', 'min_qty' => 20, 'max_qty' => 50]);
        ProductPlanning::factory()->recycle($this->tenant)->create(['product_id' => $this->bottle->id, 'lot_rule' => 'multiple', 'multiple_qty' => 12]);
        $this->demand($this->herb, 120, 5);
        $this->demand($this->bottle, 13, 5);

        $this->plan();

        $this->assertSame([20, 50, 50], MrpSuggestion::query()->where('product_id', $this->herb->id)->orderBy('qty_cartons')->pluck('qty_cartons')->all());
        $this->assertSame(24, MrpSuggestion::query()->where('product_id', $this->bottle->id)->sole()->qty_cartons);
    }

    public function test_a_products_lead_time_and_buy_decision_are_respected(): void
    {
        // Made products can be forced to be bought (a trading item), and buying takes 6 days.
        ProductPlanning::factory()->recycle($this->tenant)->create(['product_id' => $this->tonic->id, 'procurement' => 'buy', 'lead_time_days' => 6]);
        $this->tonic->update(['supplier_id' => $this->supplier->id]);
        $this->demand($this->tonic, 5, 10);

        $this->plan();

        $suggestion = MrpSuggestion::query()->sole();
        $this->assertSame('purchase', $suggestion->type);
        $this->assertSame(now()->addDays(4)->toDateString(), $suggestion->start_date->toDateString());
        $this->assertSame(0, MrpSuggestion::query()->where('product_id', $this->herb->id)->count());
    }

    public function test_an_order_that_cannot_be_ready_in_time_is_flagged_late_and_starts_today(): void
    {
        $this->demand($this->tonic, 5, 1);   // due tomorrow, but making it takes 2 days

        $this->plan();

        $make = MrpSuggestion::query()->where('type', 'manufacture')->sole();
        $this->assertSame(now()->toDateString(), $make->start_date->toDateString());
        $this->assertTrue($make->sources['late']);
    }

    public function test_safety_stock_and_reorder_point_replenish_without_any_demand(): void
    {
        $this->herb->update(['min_stock_cartons' => 10, 'reorder_level' => 30]);
        $this->bottle->update(['min_stock_cartons' => 5, 'reorder_level' => 0]);
        $this->stock($this->herb, 20);
        $this->stock($this->bottle, 3);

        $this->plan();

        // Herb: 20 on hand is above the safety stock (10) but below the reorder point (30): top up to 30.
        $this->assertSame(10, MrpSuggestion::query()->where('product_id', $this->herb->id)->sole()->qty_cartons);
        // Bottle: 3 on hand is below its safety stock of 5.
        $this->assertSame(2, MrpSuggestion::query()->where('product_id', $this->bottle->id)->sole()->qty_cartons);
    }

    public function test_make_to_order_products_ignore_safety_stock(): void
    {
        $this->tonic->update(['min_stock_cartons' => 50, 'reorder_level' => 80]);
        ProductPlanning::factory()->recycle($this->tenant)->create(['product_id' => $this->tonic->id, 'make_to_order' => true]);

        $this->plan();

        $this->assertSame(0, MrpSuggestion::query()->where('product_id', $this->tonic->id)->count());
    }

    public function test_running_again_updates_the_open_proposals_instead_of_duplicating_them(): void
    {
        $demand = $this->demand($this->herb, 8, 5);

        $first = $this->plan();
        $ids = MrpSuggestion::query()->pluck('id')->all();
        $second = $this->plan();

        $this->assertSame($ids, MrpSuggestion::query()->pluck('id')->all());
        $this->assertSame(1, MrpSuggestion::query()->where('status', 'proposed')->count());
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($second['id'], MrpSuggestion::query()->sole()->mrp_run_id);

        // Demand changes: the same proposal is updated.
        $demand->update(['qty_cartons' => 12]);
        $this->plan();
        $this->assertSame(12, MrpSuggestion::query()->sole()->qty_cartons);
        $this->assertSame(1, MrpSuggestion::query()->count());

        // Demand goes away: the proposal is withdrawn.
        $demand->update(['status' => 'cancelled']);
        $third = $this->plan();
        $this->assertSame('superseded', MrpSuggestion::query()->sole()->status);
        $this->assertNull(MrpSuggestion::query()->sole()->open_key);
        $this->assertSame(1, $third['stats']['superseded']);
    }

    public function test_a_converted_purchase_suggestion_becomes_supply_and_is_not_suggested_again(): void
    {
        $this->demand($this->herb, 8, 5);
        $this->demand($this->bottle, 4, 5);
        $this->plan();
        $ids = MrpSuggestion::query()->pluck('id')->all();

        $response = $this->postJson('/api/v1/mrp/suggestions/convert-purchases', ['suggestion_ids' => $ids])->assertCreated();

        // One purchase order for the one supplier, with both lines, waiting for an administrator to price it.
        $response->assertJsonCount(1, 'data');
        $order = PurchaseOrder::query()->sole();
        $this->assertSame('pending_pricing', $order->status);
        $this->assertSame(2, $order->lines()->count());
        $this->assertSame($this->supplier->id, $order->supplier_id);
        $this->assertSame(['converted'], MrpSuggestion::query()->pluck('status')->unique()->values()->all());
        $this->assertSame($order->id, MrpSuggestion::query()->first()->converted_id);

        $this->plan();
        $this->assertSame(0, MrpSuggestion::query()->where('status', 'proposed')->count());
        $this->assertSame(2, MrpSuggestion::query()->count());
    }

    public function test_purchase_suggestions_for_products_without_a_supplier_are_refused(): void
    {
        $loose = $this->product('Loose');
        $this->demand($loose, 3, 4);
        $this->plan();

        $suggestion = MrpSuggestion::query()->sole();
        $this->postJson('/api/v1/mrp/suggestions/convert-purchases', ['suggestion_ids' => [$suggestion->id]])
            ->assertUnprocessable()->assertJsonPath('products.0.name', 'Loose');
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame('proposed', $suggestion->fresh()->status);
        $this->getJson('/api/v1/mrp/shortages')->assertOk()->assertJsonPath('data.0.needs_supplier', true);
    }

    public function test_a_converted_manufacture_suggestion_becomes_a_draft_order_and_is_not_suggested_again(): void
    {
        $this->demand($this->tonic, 14, 9);
        $this->plan();
        $suggestion = MrpSuggestion::query()->where('type', 'manufacture')->sole();

        $response = $this->postJson("/api/v1/mrp/suggestions/{$suggestion->id}/convert")->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.output_qty_cartons', 14)->assertJsonPath('data.source_type', 'mrp_suggestion');

        $order = ProductionOrder::findOrFail($response->json('data.id'));
        $this->assertSame($suggestion->id, $order->source_id);
        $this->assertSame($suggestion->start_date->toDateString(), $order->planned_start->toDateString());
        $this->assertSame('converted', $suggestion->fresh()->status);
        $this->postJson("/api/v1/mrp/suggestions/{$suggestion->id}/convert")->assertUnprocessable();

        // The draft order's output now covers the demand, so a new run proposes no more Tonic.
        $this->plan();
        $this->assertSame(0, MrpSuggestion::query()->where('type', 'manufacture')->where('status', 'proposed')->count());
    }

    public function test_a_dismissed_suggestion_stays_dismissed_while_the_need_remains(): void
    {
        $this->demand($this->herb, 8, 5);
        $this->plan();
        $suggestion = MrpSuggestion::query()->sole();

        $this->postJson("/api/v1/mrp/suggestions/{$suggestion->id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->postJson("/api/v1/mrp/suggestions/{$suggestion->id}/dismiss")->assertUnprocessable();

        $this->plan();
        $this->assertSame(1, MrpSuggestion::query()->count());
        $this->assertSame('dismissed', $suggestion->fresh()->status);
        $this->getJson('/api/v1/mrp/suggestions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/mrp/suggestions?status=proposed')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_open_sales_orders_are_demand_and_cancelled_ones_are_not(): void
    {
        $order = $this->salesOrder($this->herb, 7, 'draft');
        $this->salesOrder($this->bottle, 9, 'cancelled');
        $this->salesOrder($this->bottle, 9, 'delivered');

        $this->plan();

        $suggestion = MrpSuggestion::query()->sole();
        $this->assertSame($this->herb->id, $suggestion->product_id);
        $this->assertSame(7, $suggestion->qty_cartons);
        $this->assertSame('sales_order', $suggestion->sources['demand'][0]['type']);
        $this->assertSame($order->id, $suggestion->sources['demand'][0]['id']);
    }

    public function test_an_order_raised_for_a_cancelled_sales_order_is_reported_or_cancelled_by_policy(): void
    {
        $so = $this->salesOrder($this->tonic, 10, 'draft');
        $mo = $this->makeOrder($this->bom, 10, ['source_type' => 'sales_order', 'source_id' => $so->id]);
        $so->update(['status' => 'cancelled']);

        $first = $this->plan();
        $this->assertSame(1, $first['stats']['orphaned_orders']);
        $this->assertSame('draft', $mo->fresh()->status);

        $this->putJson('/api/v1/manufacturing/settings', ['cancel_unstarted_on_demand_cancel' => true])->assertOk();
        $second = $this->plan();
        $this->assertSame(1, $second['stats']['cancelled_orders']);
        $this->assertSame('cancelled', $mo->fresh()->status);

        // One that is already under way is never cancelled for it.
        $so2 = $this->salesOrder($this->tonic, 10, 'draft');
        $started = $this->makeOrder($this->bom, 10, ['source_type' => 'sales_order', 'source_id' => $so2->id]);
        $this->stock($this->herb, 20);
        $this->stock($this->bottle, 20);
        $this->postJson("/api/v1/production-orders/{$started->id}/confirm")->assertOk();
        $this->postJson("/api/v1/production-orders/{$started->id}/record-production", ['qty' => 2, 'exp_date' => now()->addYear()->toDateString()])->assertOk();
        $so2->update(['status' => 'cancelled']);
        $third = $this->plan();
        $this->assertSame(0, $third['stats']['cancelled_orders']);
        $this->assertSame(1, $third['stats']['orphaned_orders']);
        $this->assertSame('in_progress', $started->fresh()->status);
    }

    public function test_demand_beyond_the_horizon_is_left_for_later(): void
    {
        $this->demand($this->herb, 8, 60);
        $this->putJson('/api/v1/manufacturing/settings', ['mrp_horizon_days' => 30])->assertOk();

        $this->plan();
        $this->assertSame(0, MrpSuggestion::query()->count());

        $this->postJson('/api/v1/mrp/plan', ['horizon_days' => 90])->assertCreated();
        $this->assertSame(1, MrpSuggestion::query()->count());
    }

    public function test_overdue_demand_is_planned_for_today(): void
    {
        $this->demand($this->herb, 8, -5);

        $this->plan();

        $this->assertSame(now()->toDateString(), MrpSuggestion::query()->sole()->due_date->toDateString());
    }

    public function test_a_run_can_be_queued_and_a_second_delivery_of_the_job_changes_nothing(): void
    {
        Queue::fake();
        $this->demand($this->herb, 8, 5);

        $response = $this->postJson('/api/v1/mrp/plan', ['async' => true])->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunMrpPlanningJob::class);

        $runId = $response->json('data.id');
        (new RunMrpPlanningJob($runId))->handle(app(MrpRunService::class));
        $this->assertSame('completed', MrpRun::find($runId)->status);
        $this->assertSame(1, MrpSuggestion::query()->count());

        // The same job delivered again is a no-op: the run is already taken.
        $stats = MrpRun::find($runId)->stats;
        (new RunMrpPlanningJob($runId))->handle(app(MrpRunService::class));
        $this->assertSame($stats, MrpRun::find($runId)->stats);
        $this->assertSame(1, MrpSuggestion::query()->count());
        $this->assertSame(1, DB::table('mrp_run_lines')->where('mrp_run_id', $runId)->count());
    }

    public function test_only_one_run_per_warehouse_may_wait_at_a_time(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/mrp/plan', ['async' => true])->assertStatus(202);
        $this->postJson('/api/v1/mrp/plan', ['async' => true])->assertUnprocessable();
    }

    public function test_a_failed_run_is_recorded_without_leaking_internals_and_leaves_nothing_behind(): void
    {
        $this->demand($this->herb, 8, 5);
        $this->mock(MrpPlanner::class)->shouldReceive('plan')->andThrow(new \RuntimeException('SQLSTATE[42S02]: select * from secret_table'));

        $run = $this->postJson('/api/v1/mrp/plan')->assertCreated()->json('data');

        $this->assertSame('failed', $run['status']);
        $this->assertStringNotContainsString('secret_table', $run['error']);
        $this->assertSame(0, MrpSuggestion::query()->count());
        $this->assertNotNull($run['finished_at']);

        // A failed run does not block the next one.
        $this->app->forgetInstance(MrpPlanner::class);
        $this->postJson('/api/v1/mrp/plan')->assertCreated();
    }

    public function test_every_run_records_what_was_asked_and_what_came_out(): void
    {
        $this->demand($this->tonic, 20, 10);
        $run = $this->plan();

        $this->assertSame('MRP00001', $run['number']);
        $this->assertSame($this->warehouse->id, $run['inputs']['warehouse_id']);
        $this->assertSame(1, $run['stats']['manufacture']);
        $this->assertSame(2, $run['stats']['purchase']);
        $this->assertNotNull($run['started_at']);
        $this->getJson('/api/v1/mrp/runs')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/mrp/runs/{$run['id']}")->assertOk()->assertJsonPath('data.number', 'MRP00001');
    }

    public function test_planning_a_large_number_of_products_stays_cheap_per_product(): void
    {
        $products = collect(range(1, 80))->map(fn ($i) => $this->product("Bulk {$i}", ['supplier_id' => $this->supplier->id]));
        foreach ($products as $product) {
            $this->demand($product, 10, 5);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $run = $this->plan();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('completed', $run['status']);
        $this->assertSame(80, MrpSuggestion::query()->count());
        // A handful of queries per product (its recipe lookup, stock, its line and its suggestion) — not one per demand row per step.
        $this->assertLessThan(80 * 12, $queries, "Planning 80 products took {$queries} queries.");
    }

    public function test_permissions(): void
    {
        $this->demand($this->herb, 8, 5);
        $this->plan();
        $suggestion = MrpSuggestion::query()->sole();

        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/mrp/runs')->assertForbidden();
        $this->postJson('/api/v1/mrp/plan')->assertForbidden();

        // Warehouse Employee may plan (add) but converting is an approval.
        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/mrp/suggestions')->assertOk();
        $this->postJson('/api/v1/mrp/suggestions/convert-purchases', ['suggestion_ids' => [$suggestion->id]])->assertForbidden();

        // Converting needs the right to create the document too.
        $user = $this->actingAsRole('Warehouse Manager');
        $this->postJson('/api/v1/mrp/suggestions/convert-purchases', ['suggestion_ids' => [$suggestion->id]])->assertForbidden();   // no purchase-orders.create
        $this->assertSame('proposed', $suggestion->fresh()->status);

        // Another company's suggestion is simply not found.
        $other = MrpSuggestion::query()->create([
            'tenant_id' => Tenant::factory()->create()->id, 'type' => 'purchase', 'product_id' => $this->herb->id, 'warehouse_id' => $this->warehouse->id,
            'qty_cartons' => 1, 'start_date' => now(), 'due_date' => now(), 'fingerprint' => 'x', 'status' => 'proposed',
        ]);
        $this->actingAsRole('Administrator');
        $this->postJson("/api/v1/mrp/suggestions/{$other->id}/dismiss")->assertNotFound();
        $this->assertNotNull($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        $run = $this->postJson('/api/v1/mrp/plan')->assertCreated()->json('data');
        $this->assertSame('completed', $run['status'], (string) $run['error']);

        return $run;
    }

    private function demand($product, int $qty, int $inDays): MrpDemand
    {
        return MrpDemand::factory()->recycle($this->tenant)->create([
            'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => $qty, 'due_date' => now()->addDays($inDays)->toDateString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(Bom $bom, int $qty, array $attributes = []): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', $attributes + ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => $qty])->assertCreated()->json('data.id');

        return ProductionOrder::findOrFail($id);
    }

    private function purchaseOrder($product, int $qty, string $status): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->recycle($this->tenant)->create([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'status' => $status, 'expected_date' => now()->addDays(3)->toDateString(),
        ]);
        $order->lines()->create(['product_id' => $product->id, 'qty_cartons' => $qty, 'cost_per_carton' => 1, 'total' => $qty]);

        return $order;
    }

    private function salesOrder($product, int $qty, string $status): SalesOrder
    {
        $customer = Customer::factory()->recycle($this->tenant)->create();
        $rep = User::factory()->recycle($this->tenant)->create();
        $order = SalesOrder::factory()->recycle([$this->tenant, $customer, $this->warehouse])->create([
            'sales_rep_id' => $rep->id, 'status' => $status, 'order_date' => now()->addDays(4)->toDateString(), 'stock_deducted' => $status === 'delivered',
        ]);
        SalesOrderLine::factory()->for($order, 'salesOrder')->create(['product_id' => $product->id, 'qty' => $qty, 'unit' => 'Carton', 'free_qty' => 0]);

        return $order;
    }
}
