<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Modules\HR\Models\Holiday;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

/**
 * 2026-11-01 is a Sunday, and the work centers work Sunday to Thursday, 08:00-16:00.
 * One order makes 10 cartons through two operations:
 *   Mix  (center A): 30 min setup + 2 min/carton + 10 min cleanup = 60 min
 *   Fill (center B): 1 min/carton = 10 min
 */
class RoutingAndWorkOrderTest extends ManufacturingTestCase
{
    private WorkCenter $mixer;

    private WorkCenter $filler;

    private Routing $routing;

    private Bom $bom;

    private $tonic;

    private $herb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mixer = WorkCenter::factory()->recycle($this->tenant)->withWeeklyShift()->create(['code' => 'MIX', 'name' => 'Mixer', 'cost_per_hour' => 60, 'labor_cost_per_hour' => 30]);
        $this->filler = WorkCenter::factory()->recycle($this->tenant)->withWeeklyShift()->create(['code' => 'FILL', 'name' => 'Filler', 'cost_per_hour' => 12, 'labor_cost_per_hour' => 18]);
        $this->actingAsRole('Administrator');

        $this->routing = Routing::factory()->recycle($this->tenant)->create(['code' => 'R1']);
        $this->routing->operations()->create(['sequence' => 10, 'name' => 'Mix', 'work_center_id' => $this->mixer->id, 'setup_minutes' => 30, 'run_minutes' => 2, 'cleanup_minutes' => 10]);
        $this->routing->operations()->create(['sequence' => 20, 'name' => 'Fill', 'work_center_id' => $this->filler->id, 'run_minutes' => 1]);

        $this->tonic = $this->product('Tonic');
        $this->herb = $this->product('Herb', ['pack_cost_price' => 1]);
        $this->bom = $this->bom($this->tonic, [[$this->herb, 5]], ['output_qty_cartons' => 10, 'routing_id' => $this->routing->id]);
        $this->stock($this->herb, 50, 10);
    }

    public function test_a_routing_with_operations_is_created_and_refuses_a_dependency_on_a_later_step(): void
    {
        $id = $this->postJson('/api/v1/routings', [
            'code' => 'R2', 'name' => 'Two steps',
            'operations' => [
                ['sequence' => 10, 'name' => 'Cut', 'work_center_id' => $this->mixer->id, 'run_minutes' => 1],
                ['sequence' => 20, 'name' => 'Pack', 'work_center_id' => $this->filler->id, 'run_minutes' => 1, 'depends_on_id' => 10],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.operations')->json('data.id');

        $operations = Routing::find($id)->operations;
        $this->assertSame($operations[0]->id, $operations[1]->depends_on_id);

        $first = $operations[0];
        $this->putJson("/api/v1/routings/{$id}/operations/{$first->id}", ['depends_on_id' => $operations[1]->id])->assertUnprocessable();
        $this->postJson("/api/v1/routings/{$id}/operations", ['sequence' => 10, 'name' => 'Dup', 'work_center_id' => $this->mixer->id])->assertUnprocessable();
        $this->postJson("/api/v1/routings/{$id}/operations", ['sequence' => 30, 'name' => 'Label', 'work_center_id' => $this->filler->id, 'depends_on_id' => $first->id])->assertCreated();
        $this->deleteJson("/api/v1/routings/{$id}/operations/{$first->id}")->assertNoContent();
    }

    public function test_changing_a_routing_makes_a_new_version_of_the_recipes_that_use_it(): void
    {
        $this->assertSame(1, $this->bom->version);

        $this->postJson("/api/v1/routings/{$this->routing->id}/operations", ['sequence' => 30, 'name' => 'Label', 'work_center_id' => $this->filler->id, 'run_minutes' => 1])->assertCreated();

        $bom = $this->bom->fresh();
        $this->assertSame(2, $bom->version);
        $this->assertCount(3, $bom->revisions()->where('revision_no', 2)->sole()->snapshot['operations']);
    }

    public function test_planning_creates_the_work_orders_and_books_them_one_after_the_other(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $order = $this->confirmed();

        $response = $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonCount(2, 'data.work_orders');

        [$mix, $fill] = $order->fresh()->workOrders->all();
        $this->assertSame(['WO00001', 'WO00002'], [$mix->number, $fill->number]);
        $this->assertSame([60, 10], [$mix->planned_minutes, $fill->planned_minutes]);
        // Operations become ready when the order starts.
        $this->assertSame(['pending', 'pending'], [$mix->status, $fill->status]);
        // The mixer starts when its shift does (08:00), the filler as soon as the mixer is done.
        $this->assertSame('2026-11-01 08:00:00', $mix->scheduled_start->toDateTimeString());
        $this->assertSame('2026-11-01 09:00:00', $mix->scheduled_end->toDateTimeString());
        $this->assertSame('2026-11-01 09:00:00', $fill->scheduled_start->toDateTimeString());
        $this->assertSame('2026-11-01 09:10:00', $fill->scheduled_end->toDateTimeString());
        $this->assertSame($mix->id, $fill->depends_on_id);
        $this->assertSame('2026-11-01 09:10:00', $order->fresh()->planned_finish->toDateTimeString());
    }

    public function test_a_second_order_waits_for_the_center_to_be_free(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $first = $this->confirmed();
        $second = $this->confirmed();

        $this->postJson("/api/v1/production-orders/{$first->id}/plan")->assertOk();
        $this->postJson("/api/v1/production-orders/{$second->id}/plan")->assertOk();

        $mixes = WorkOrder::query()->where('work_center_id', $this->mixer->id)->orderBy('scheduled_start')->get();
        $this->assertSame(['2026-11-01 08:00:00', '2026-11-01 09:00:00'], $mixes->map(fn ($w) => $w->scheduled_start->toDateTimeString())->all());
        $this->assertSame('2026-11-01 10:00:00', $mixes[1]->scheduled_end->toDateTimeString());
    }

    public function test_a_center_with_capacity_two_runs_two_operations_at_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $this->mixer->update(['capacity' => 2]);
        $first = $this->confirmed();
        $second = $this->confirmed();
        $third = $this->confirmed();

        foreach ([$first, $second, $third] as $order) {
            $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        }

        $starts = WorkOrder::query()->where('work_center_id', $this->mixer->id)->orderBy('scheduled_start')->get()->map(fn ($w) => $w->scheduled_start->format('H:i'))->all();
        $this->assertSame(['08:00', '08:00', '09:00'], $starts);
    }

    public function test_downtime_and_holidays_push_the_schedule_back(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        // The mixer is down 08:00-10:00 on the first day and the whole of the second day is a holiday.
        $this->postJson("/api/v1/work-centers/{$this->mixer->id}/downtimes", ['starts_at' => '2026-11-01 08:00:00', 'ends_at' => '2026-11-01 10:00:00', 'reason' => 'Belt change'])->assertCreated();
        Holiday::factory()->recycle($this->tenant)->create(['start_date' => '2026-11-02', 'end_date' => '2026-11-02']);

        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        $mix = $order->fresh()->workOrders->first();
        $this->assertSame('2026-11-01 10:00:00', $mix->scheduled_start->toDateTimeString());

        // A 10-hour operation starting at 14:00 works the last two hours of the day, skips the holiday, and finishes on the 3rd.
        $this->routing->operations()->where('sequence', 10)->update(['setup_minutes' => 0, 'cleanup_minutes' => 0, 'run_minutes' => 60]);
        $this->bom->update(['version' => 3]);
        $this->travelTo(Carbon::parse('2026-11-01 14:00:00'));
        $this->mixer->downtimes()->delete();
        $long = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$long->id}/plan")->assertOk();

        $mix = $long->fresh()->workOrders->first();
        $this->assertSame(600, $mix->planned_minutes);
        $this->assertSame('2026-11-01 14:00:00', $mix->scheduled_start->toDateTimeString());
        // 2 h on Sunday, nothing on the Monday holiday, 8 h on Tuesday: 10 h, finishing when the Tuesday shift ends.
        $this->assertSame('2026-11-03 16:00:00', $mix->scheduled_end->toDateTimeString());
    }

    public function test_work_orders_must_run_in_order_and_record_what_they_took(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        [$mix, $fill] = $order->fresh()->workOrders->all();

        // The filler cannot start before the mixer is done.
        $this->postJson("/api/v1/work-orders/{$fill->id}/start")->assertUnprocessable();
        $this->assertSame('planned', $order->fresh()->status);   // the refused start did not start the order either

        $this->travelTo(Carbon::parse('2026-11-01 08:05:00'));
        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('in_progress', $order->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-01 08:20:00'));
        $this->postJson("/api/v1/work-orders/{$mix->id}/pause", ['reason' => 'Lunch'])->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.actual_minutes', 15);
        $this->travelTo(Carbon::parse('2026-11-01 08:30:00'));
        $this->postJson("/api/v1/work-orders/{$mix->id}/resume")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->travelTo(Carbon::parse('2026-11-01 09:15:00'));
        $done = $this->postJson("/api/v1/work-orders/{$mix->id}/complete", ['notes' => 'Smooth'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.qty_processed', 10)->assertJsonPath('data.actual_minutes', 60)->assertJsonPath('data.duration_variance_minutes', 0);

        // 1 hour on a center costing 60 + 30 an hour.
        $this->assertSame('30.00', $done->json('data.labor_cost'));
        $this->assertSame('60.00', $done->json('data.machine_cost'));
        $this->assertSame('90.00', $order->fresh()->actual_operation_cost);
        $this->assertSame('ready', $fill->fresh()->status);
        $this->assertCount(2, $this->getJson("/api/v1/work-orders/{$mix->id}")->json('data.time_logs'));
    }

    public function test_finished_goods_can_only_be_received_for_what_the_last_operation_has_processed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        [$mix, $fill] = $order->fresh()->workOrders->all();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 1, 'exp_date' => now()->addYear()->toDateString()])->assertUnprocessable();

        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$mix->id}/complete", ['qty_rejected' => 1])->assertOk()->assertJsonPath('data.qty_processed', 9)->assertJsonPath('data.qty_rejected', 1);
        $this->assertSame(1, $order->fresh()->rejected_qty_cartons);

        // The filler can only take what the mixer passed on.
        $this->postJson("/api/v1/work-orders/{$fill->id}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$fill->id}/complete", ['qty_processed' => 10])->assertUnprocessable();
        $this->postJson("/api/v1/work-orders/{$fill->id}/complete", ['qty_processed' => 8])->assertOk();

        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 9, 'exp_date' => now()->addYear()->toDateString()])->assertUnprocessable();
        $this->postJson("/api/v1/production-orders/{$order->id}/record-production", ['qty' => 8, 'exp_date' => now()->addYear()->toDateString()])->assertOk();
    }

    public function test_an_order_cannot_complete_while_operations_are_open_and_completing_one_does_not_complete_the_order(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        [$mix, $fill] = $order->fresh()->workOrders->all();

        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable();

        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$mix->id}/complete")->assertOk();
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable()->assertJsonPath('message', 'Complete the work orders first: 1 still open.');

        $this->postJson("/api/v1/work-orders/{$fill->id}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$fill->id}/complete")->assertOk();
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.produced_qty_cartons', 10);
    }

    public function test_pausing_the_order_pauses_running_operations_and_stops_new_ones(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        $mix = $order->fresh()->workOrders->first();
        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertOk();

        $this->travelTo(Carbon::parse('2026-11-01 08:10:00'));
        $this->postJson("/api/v1/production-orders/{$order->id}/pause")->assertOk();
        $this->assertSame('paused', $mix->fresh()->status);
        $this->assertSame(10, $mix->fresh()->actual_minutes);
        $this->postJson("/api/v1/work-orders/{$mix->id}/resume")->assertUnprocessable();

        $this->postJson("/api/v1/production-orders/{$order->id}/resume")->assertOk();
        $this->postJson("/api/v1/work-orders/{$mix->id}/resume")->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_an_operation_cannot_be_started_on_a_cancelled_or_unplanned_order(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        $mix = $order->fresh()->workOrders->first();

        $this->postJson("/api/v1/production-orders/{$order->id}/cancel")->assertOk();
        $this->assertSame(['cancelled', 'cancelled'], $order->fresh()->workOrders->pluck('status')->all());
        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertUnprocessable();
    }

    public function test_rescheduling_refuses_a_busy_time_unless_told_to_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $first = $this->confirmed();
        $second = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$first->id}/plan")->assertOk();
        $this->postJson("/api/v1/production-orders/{$second->id}/plan")->assertOk();
        $secondMix = $second->fresh()->workOrders->first();   // 09:00-10:00

        // 08:30 is inside the first order's booking on the single-capacity mixer.
        $this->postJson("/api/v1/work-orders/{$secondMix->id}/reschedule", ['scheduled_start' => '2026-11-01 08:30:00'])
            ->assertUnprocessable()->assertJsonPath('next_free_start', '2026-11-01 09:00:00')->assertJsonCount(1, 'conflicts');

        $this->postJson("/api/v1/work-orders/{$secondMix->id}/reschedule", ['scheduled_start' => '2026-11-01 08:30:00', 'auto_shift' => true])
            ->assertOk()->assertJsonPath('data.scheduled_start', Carbon::parse('2026-11-01 09:00:00')->toJSON());

        // A free time is accepted as asked, but never before the operation it depends on ends.
        $this->postJson("/api/v1/work-orders/{$secondMix->id}/reschedule", ['scheduled_start' => '2026-11-02 08:00:00'])->assertOk();
        $secondFill = $second->fresh()->workOrders->last();
        $this->postJson("/api/v1/work-orders/{$secondFill->id}/reschedule", ['scheduled_start' => '2026-11-01 11:00:00'])->assertUnprocessable();
    }

    public function test_the_center_schedule_shows_the_bookings_and_the_working_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();

        $schedule = $this->getJson("/api/v1/work-centers/{$this->mixer->id}/schedule?from=2026-11-01&to=2026-11-07")->assertOk()->json('data');

        // Sunday to Thursday, eight hours each.
        $this->assertSame(5 * 480, $schedule['available_minutes']);
        $this->assertSame(60, $schedule['booked_minutes']);
        $this->assertCount(1, $schedule['work_orders']);
    }

    public function test_with_work_in_progress_accounting_operation_time_is_booked_when_it_completes(): void
    {
        $this->putJson('/api/v1/manufacturing/settings', ['wip_accounting' => true])->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        $mix = $order->fresh()->workOrders->first();
        $this->postJson("/api/v1/work-orders/{$mix->id}/start")->assertOk();
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));
        $this->postJson("/api/v1/work-orders/{$mix->id}/complete")->assertOk();

        $this->assertSame([['1350', 90.0, 0.0], ['6400', 0.0, 90.0]], $this->entries($order->id)[0]);
    }

    public function test_an_operation_with_no_times_takes_the_default_duration_from_the_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 07:00:00'));
        $this->putJson('/api/v1/manufacturing/settings', ['default_operation_minutes' => 45])->assertOk();
        $this->routing->operations()->where('sequence', 20)->update(['run_minutes' => 0]);   // "Fill" has no times at all

        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();

        $this->assertSame([60, 45], $order->fresh()->workOrders->pluck('planned_minutes')->all());
        $this->assertSame('2026-11-01 09:45:00', $order->fresh()->workOrders->last()->scheduled_end->toDateTimeString());
    }

    public function test_work_center_management_and_its_permissions(): void
    {
        $id = $this->postJson('/api/v1/work-centers', [
            'code' => 'OVEN', 'name' => 'Oven', 'capacity' => 2, 'cost_per_hour' => 40,
            'calendar' => [['weekday' => 0, 'start_time' => '06:00', 'end_time' => '14:00']],
        ])->assertCreated()->assertJsonPath('data.calendar.0.start_time', '06:00')->json('data.id');

        $this->postJson('/api/v1/work-centers', ['code' => 'OVEN', 'name' => 'Dup'])->assertUnprocessable();
        $this->putJson("/api/v1/work-centers/{$id}/calendar", ['shifts' => [['weekday' => 1, 'start_time' => '07:00', 'end_time' => '15:00'], ['weekday' => 2, 'start_time' => '07:00', 'end_time' => '15:00']]])
            ->assertOk()->assertJsonCount(2, 'data.calendar');
        $this->putJson("/api/v1/work-centers/{$id}/calendar", ['shifts' => [['weekday' => 1, 'start_time' => '15:00', 'end_time' => '07:00']]])->assertUnprocessable();

        // A center that is part of a routing cannot be deleted; an unused one can.
        $this->deleteJson("/api/v1/work-centers/{$this->mixer->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/work-centers/{$id}")->assertNoContent();

        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/work-centers')->assertOk();
        $this->postJson('/api/v1/work-centers', ['code' => 'X', 'name' => 'X'])->assertCreated();   // may add and edit...
        $this->deleteJson("/api/v1/work-centers/{$this->filler->id}")->assertForbidden();             // ...not delete

        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/work-centers')->assertForbidden();
    }

    private function confirmed(): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', [
            'bom_id' => $this->bom->fresh()->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk();

        return ProductionOrder::findOrFail($id);
    }
}
