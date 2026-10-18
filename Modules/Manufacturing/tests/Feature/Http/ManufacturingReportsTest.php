<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

/**
 * The worked example again: one run of 10 cartons Tonic = 5 Herb (10 each) + 3 Bottle (20 each) + 100 conversion = 210.
 */
class ManufacturingReportsTest extends ManufacturingTestCase
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
        $this->stock($this->herb, 200, 10);
        $this->stock($this->bottle, 200, 20);
        $this->actingAsRole('Administrator');
    }

    public function test_the_production_report_compares_planned_with_produced_by_product_month_and_warehouse(): void
    {
        $a = $this->order(10);
        $this->complete($a);
        $b = $this->order(10);
        $this->act($b, 'confirm');
        $this->act($b, 'record-production', ['qty' => 8, 'rejected' => 2]);
        $this->act($b, 'complete', ['produce_remaining' => false]);
        $cancelled = $this->order(10);
        $this->act($cancelled, 'cancel');

        $rows = $this->getJson('/api/v1/reports/manufacturing/production')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Tonic', $rows[0]['label']);
        $this->assertSame(2, $rows[0]['orders']);        // the cancelled order is left out
        $this->assertSame(20, $rows[0]['planned_qty']);
        $this->assertSame(18, $rows[0]['produced_qty']);
        $this->assertSame(2, $rows[0]['rejected_qty']);
        $this->assertEquals(90.0, $rows[0]['completion_pct']);
        $this->assertEquals(90.0, $rows[0]['yield_pct']);

        $month = $this->getJson('/api/v1/reports/manufacturing/production?group_by=month')->assertOk()->json('data');
        $this->assertSame(now()->format('Y-m'), $month[0]['label']);
        $this->getJson('/api/v1/reports/manufacturing/production?group_by=warehouse')->assertOk()->assertJsonPath('data.0.label', $this->warehouse->name);
        $this->getJson('/api/v1/reports/manufacturing/production?status=cancelled')->assertOk()->assertJsonPath('data.0.orders', 1);
        $this->getJson('/api/v1/reports/manufacturing/production?product_id='.$this->herb->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/manufacturing/production?group_by=nonsense')->assertUnprocessable();
        $this->getJson('/api/v1/reports/manufacturing/production?from=2026-12-31&to=2026-01-01')->assertUnprocessable();
    }

    public function test_material_consumption_shows_used_and_scrapped_cartons_and_hides_value_without_the_cost_permission(): void
    {
        $order = $this->order(10);
        $this->act($order, 'confirm');
        $this->act($order, 'record-scrap', ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled']);
        $this->act($order, 'complete');

        $herb = collect($this->getJson('/api/v1/reports/manufacturing/material-consumption')->assertOk()->json('data'))->firstWhere('product.name', 'Herb');

        $this->assertSame(5, $herb['used_qty']);
        $this->assertSame(2, $herb['scrap_qty']);
        $this->assertEquals(50.0, $herb['used_value']);
        $this->assertEquals(20.0, $herb['scrap_value']);

        $this->actingAsRole('Warehouse Manager');
        $herb = collect($this->getJson('/api/v1/reports/manufacturing/material-consumption')->assertOk()->json('data'))->firstWhere('product.name', 'Herb');
        $this->assertSame(5, $herb['used_qty']);
        $this->assertArrayNotHasKey('used_value', $herb);
        $this->getJson('/api/v1/reports/manufacturing/material-consumption?product_id='.$this->bottle->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_scrap_report_groups_by_reason_and_gives_the_rejection_rate(): void
    {
        $order = $this->order(10);
        $this->act($order, 'confirm');
        $this->act($order, 'record-scrap', ['product_id' => $this->herb->id, 'cartons' => 2, 'reason' => 'Spilled']);
        $this->act($order, 'record-production', ['qty' => 8, 'rejected' => 2, 'reject_reason' => 'Cracked seal']);
        $this->act($order, 'complete');

        $response = $this->getJson('/api/v1/reports/manufacturing/scrap')->assertOk();

        $reasons = collect($response->json('data'))->pluck('qty', 'reason');
        $this->assertSame(2, $reasons['Spilled']);
        $this->assertSame(2, $reasons['Cracked seal']);
        $this->assertEquals(20.0, $response->json('rejection_rate_pct'));
        $this->getJson('/api/v1/reports/manufacturing/scrap?kind=component')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_work_center_utilization_compares_available_scheduled_and_worked_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $center = WorkCenter::factory()->recycle($this->tenant)->withWeeklyShift()->create(['code' => 'MIX', 'name' => 'Mixer', 'capacity' => 1]);
        $routing = Routing::factory()->recycle($this->tenant)->create();
        $routing->operations()->create(['sequence' => 10, 'name' => 'Mix', 'work_center_id' => $center->id, 'setup_minutes' => 30, 'run_minutes' => 3, 'cleanup_minutes' => 0]);   // 60 min for 10 cartons
        $this->bom->update(['routing_id' => $routing->id]);

        $order = $this->order(10);
        $this->act($order, 'confirm');
        $this->act($order, 'plan');
        $workOrder = $order->fresh()->workOrders->sole();
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/start")->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01 09:15:00'));   // took 75 minutes
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete")->assertOk();

        $row = $this->getJson('/api/v1/reports/manufacturing/work-center-utilization?from=2026-11-01&to=2026-11-07')->assertOk()->json('data.0');

        $this->assertSame('MIX', $row['work_center']['code']);
        $this->assertSame(2400, $row['available_minutes']);       // Sunday-Thursday, 8 hours
        $this->assertSame(60, $row['scheduled_minutes']);
        $this->assertSame(75, $row['actual_minutes']);
        $this->assertSame(15, $row['duration_variance_minutes']);
        $this->assertEquals(2.5, $row['schedule_load_pct']);
        $this->assertEquals(3.1, $row['utilization_pct']);
        $this->assertSame(1, $row['operations_completed']);
    }

    public function test_lead_times_show_delay_against_the_plan(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->order(10, ['planned_start' => '2026-11-01 08:00:00', 'planned_finish' => '2026-11-01 12:00:00']);
        $this->act($order, 'confirm');
        $this->act($order, 'start');
        $this->travelTo(Carbon::parse('2026-11-01 13:00:00'));
        $this->act($order, 'complete');

        $response = $this->getJson('/api/v1/reports/manufacturing/lead-times')->assertOk();

        $this->assertSame(60, $response->json('data.0.delay_minutes'));
        $this->assertSame(300, $response->json('data.0.lead_minutes'));
        $this->assertFalse($response->json('data.0.on_time'));
        $this->assertSame(1, $response->json('orders'));
        $this->assertEquals(300.0, $response->json('avg_lead_minutes'));
        $this->assertEquals(0.0, $response->json('on_time_pct'));
    }

    public function test_traceability_goes_both_ways(): void
    {
        $order = $this->order(10);
        $this->complete($order);
        $finishedBatch = $order->fresh()->batch_no;
        $herbBatch = $this->herb->batches()->first()->batch_no;

        $forward = $this->getJson('/api/v1/reports/manufacturing/traceability?batch_no='.$herbBatch)->assertOk()->json('data');
        $this->assertSame($order->fresh()->number, $forward['used_in'][0]['number']);
        $this->assertSame($finishedBatch, $forward['used_in'][0]['outputs'][0]['batch_no']);
        $this->assertSame([], $forward['made_by']);

        $backward = $this->getJson('/api/v1/reports/manufacturing/traceability?batch_no='.$finishedBatch)->assertOk()->json('data');
        $this->assertSame($order->fresh()->number, $backward['made_by'][0]['number']);
        $this->assertEqualsCanonicalizing(['Herb', 'Bottle'], array_column($backward['made_by'][0]['inputs'], 'product'));

        $this->getJson('/api/v1/reports/manufacturing/traceability')->assertUnprocessable();
        $this->getJson('/api/v1/reports/manufacturing/traceability?batch_no=NOPE')->assertOk()->assertJsonPath('data.made_by', [])->assertJsonPath('data.used_in', []);
    }

    public function test_cost_variance_is_for_people_who_may_see_cost(): void
    {
        // Herb really costs 12 a carton (not the standard 10): material is 5 x 12 + 3 x 20 = 120 against a plan of 110.
        InventoryBatch::query()->where('product_id', $this->herb->id)->update(['cost_per_carton' => 12]);
        $order = $this->order(10);
        $this->complete($order);

        $row = $this->getJson('/api/v1/reports/manufacturing/cost-variance')->assertOk()->json('data.0');

        $this->assertEquals(210.0, $row['planned']);
        $this->assertEquals(220.0, $row['actual']);
        $this->assertEquals(10.0, $row['variance']);
        $this->assertEquals(4.8, $row['variance_pct']);
        $this->assertEquals(22.0, $row['unit_cost']);

        $this->actingAsRole('Warehouse Manager');
        $this->getJson('/api/v1/reports/manufacturing/cost-variance')->assertForbidden();
        $this->getJson('/api/v1/reports/manufacturing/wip-valuation')->assertForbidden();
        $this->getJson('/api/v1/reports/manufacturing/production')->assertOk();

        $this->actingAsRole('Accountant');
        $this->getJson('/api/v1/reports/manufacturing/cost-variance')->assertOk();
    }

    public function test_wip_valuation_matches_the_work_in_progress_account(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['wip_accounting' => true])->assertOk();
        $order = $this->order(10);
        $this->act($order, 'confirm');
        $this->act($order, 'record-production', ['qty' => 4]);   // 60 issued + 40 overhead - 80 to stock = 20 left in progress

        $report = $this->getJson('/api/v1/reports/manufacturing/wip-valuation')->assertOk();

        $this->assertEquals(20.0, $report->json('data.0.wip'));
        $this->assertEquals(60.0, $report->json('data.0.material'));
        $this->assertEquals(40.0, $report->json('data.0.overhead'));
        $this->assertEquals(80.0, $report->json('data.0.capitalized'));
        $this->assertEquals(20.0, $report->json('total_wip'));
        $this->assertEquals(20.0, $report->json('ledger_balance_1350'));

        $this->act($order, 'complete');
        $this->getJson('/api/v1/reports/manufacturing/wip-valuation')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('ledger_balance_1350', 0);
    }

    public function test_reports_export_with_the_export_permission_only(): void
    {
        $this->complete($this->order(10));

        $this->get('/api/v1/reports/manufacturing/production?export=csv')->assertOk()->assertHeader('content-disposition');
        $this->get('/api/v1/reports/manufacturing/work-center-utilization?export=csv')->assertOk();
        $this->get('/api/v1/reports/manufacturing/cost-variance?export=csv')->assertOk();

        $this->actingAsRole('Warehouse Employee');
        $this->get('/api/v1/reports/manufacturing/production')->assertOk();
        $this->get('/api/v1/reports/manufacturing/production?export=csv')->assertForbidden();

        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/reports/manufacturing/production')->assertForbidden();
    }

    public function test_another_companys_orders_are_not_in_the_report(): void
    {
        $this->complete($this->order(10));
        $foreign = ProductionOrder::factory()->completed()->create();

        $rows = $this->getJson('/api/v1/reports/manufacturing/production')->assertOk()->json('data');

        $this->assertSame(1, array_sum(array_column($rows, 'orders')));
        $this->assertNotNull($foreign);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(int $qty, array $attributes = []): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', $attributes + [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => $qty, 'exp_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');

        return ProductionOrder::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function act(ProductionOrder $order, string $action, array $body = []): void
    {
        if ($action === 'record-production') {
            $body += ['exp_date' => now()->addYear()->toDateString()];
        }

        $this->postJson("/api/v1/production-orders/{$order->id}/{$action}", $body)->assertSuccessful();
    }

    private function complete(ProductionOrder $order): void
    {
        $this->act($order, 'complete');
    }
}
