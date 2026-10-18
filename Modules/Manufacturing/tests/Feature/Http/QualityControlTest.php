<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\QualityAlert;
use Modules\Manufacturing\Models\QualityCheck;
use Modules\Manufacturing\Models\QualityCheckDefinition;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

class QualityControlTest extends ManufacturingTestCase
{
    private $tonic;

    private $bom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tonic = $this->product('Tonic');
        $herb = $this->product('Herb');
        $this->bom = $this->bom($this->tonic, [[$herb, 2]], ['output_qty_cartons' => 10]);
        $this->stock($herb, 20, 10);
        $this->actingAsRole('Administrator');
    }

    public function test_a_mandatory_final_check_blocks_completion_until_it_passes(): void
    {
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Appearance', 'point' => 'final', 'product_id' => $this->tonic->id]);
        $order = $this->confirmed();

        $check = QualityCheck::query()->where('production_order_id', $order->id)->sole();
        $this->assertSame('pending', $check->status);

        $this->postJson("/api/v1/production-orders/{$order->id}/complete")
            ->assertUnprocessable()->assertJsonPath('message', 'Mandatory quality checks have not passed.')->assertJsonPath('quality_checks.0.name', 'Appearance');
        // The refused completion rolled back everything, including the stock it had issued.
        $this->assertSame(0, $order->fresh()->produced_qty_cartons);
        $this->assertSame('confirmed', $order->fresh()->status);

        $this->postJson("/api/v1/quality-checks/{$check->id}/record", ['result' => 'passed', 'notes' => 'Clear'])->assertOk()->assertJsonPath('data.status', 'passed');
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_check_that_does_not_apply_to_the_product_is_not_raised(): void
    {
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Other product', 'product_id' => $this->product('Other')->id]);
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Anything', 'product_id' => null]);
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Retired', 'active' => false]);

        $order = $this->confirmed();

        $this->assertSame(['Anything'], QualityCheck::query()->where('production_order_id', $order->id)->pluck('name')->all());
    }

    public function test_a_measured_value_outside_the_limits_fails_and_opens_an_alert(): void
    {
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'pH', 'type' => 'measure', 'min_value' => 5, 'max_value' => 7.5, 'unit_label' => 'pH']);
        $order = $this->confirmed();
        $check = QualityCheck::query()->where('production_order_id', $order->id)->sole();

        $this->postJson("/api/v1/quality-checks/{$check->id}/record", [])->assertUnprocessable();   // a value is needed
        $this->postJson("/api/v1/quality-checks/{$check->id}/record", ['measured_value' => 8.1, 'defect_class' => 'Too alkaline', 'severity' => 'high', 'corrective_action' => 'Re-blend'])
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.alerts.0.severity', 'high');

        $this->assertSame(1, QualityAlert::query()->where('production_order_id', $order->id)->where('status', 'open')->count());
        $this->postJson("/api/v1/quality-checks/{$check->id}/record", ['measured_value' => 6])->assertUnprocessable();   // already has a result
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable();

        // Inspecting again (a new check from the same definition) that passes lifts the block.
        $retest = $this->postJson('/api/v1/quality-checks', ['definition_id' => $check->definition_id, 'production_order_id' => $order->id, 'product_id' => $this->tonic->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertUnprocessable();   // the retest is still pending
        $this->postJson("/api/v1/quality-checks/{$retest}/record", ['measured_value' => 6.8])->assertOk()->assertJsonPath('data.status', 'passed');
        $this->postJson("/api/v1/production-orders/{$order->id}/complete")->assertOk();
    }

    public function test_an_alert_is_resolved_once(): void
    {
        $alert = $this->failedAlert();

        $this->getJson('/api/v1/quality-alerts?status=open')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/quality-alerts/{$alert->id}/resolve", ['corrective_action' => 'Batch re-mixed'])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.corrective_action', 'Batch re-mixed');
        $this->postJson("/api/v1/quality-alerts/{$alert->id}/resolve")->assertUnprocessable();
        $this->getJson('/api/v1/quality-alerts?status=open')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_operation_check_blocks_that_operation_until_it_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));
        $center = WorkCenter::factory()->recycle($this->tenant)->withWeeklyShift()->create();
        $routing = Routing::factory()->recycle($this->tenant)->create();
        $operation = $routing->operations()->create(['sequence' => 10, 'name' => 'Blend', 'work_center_id' => $center->id, 'run_minutes' => 1]);
        $this->bom->update(['routing_id' => $routing->id]);
        QualityCheckDefinition::factory()->recycle($this->tenant)->create(['name' => 'Blend uniformity', 'point' => 'in_process', 'routing_operation_id' => $operation->id]);

        $order = $this->confirmed();
        $this->postJson("/api/v1/production-orders/{$order->id}/plan")->assertOk();
        $workOrder = $order->fresh()->workOrders->sole();
        $check = QualityCheck::query()->where('work_order_id', $workOrder->id)->sole();

        $this->postJson("/api/v1/work-orders/{$workOrder->id}/start")->assertOk();
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete")->assertUnprocessable()->assertJsonPath('quality_checks.0.name', 'Blend uniformity');

        $this->postJson("/api/v1/quality-checks/{$check->id}/record", ['result' => 'passed'])->assertOk();
        $this->postJson("/api/v1/work-orders/{$workOrder->id}/complete")->assertOk();
    }

    public function test_an_incoming_inspection_can_be_raised_by_hand_and_filtered(): void
    {
        $id = $this->postJson('/api/v1/quality-checks', [
            'name' => 'Incoming herb', 'point' => 'incoming', 'product_id' => $this->tonic->id, 'reference_type' => 'purchase_order', 'reference_id' => 'PO-1', 'mandatory' => false,
        ])->assertCreated()->assertJsonPath('data.point', 'incoming')->json('data.id');

        $this->postJson("/api/v1/quality-checks/{$id}/record", ['result' => 'failed', 'defect_class' => 'Damp'])->assertOk()->assertJsonPath('data.status', 'failed');

        $this->getJson('/api/v1/quality-checks?point=incoming&status=failed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/quality-checks?point=final')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/quality-checks', ['point' => 'incoming'])->assertUnprocessable();   // needs a name or a definition
    }

    public function test_definitions_are_managed_validated_and_permissioned(): void
    {
        $id = $this->postJson('/api/v1/quality-check-definitions', ['name' => 'Fill volume', 'type' => 'measure', 'min_value' => 98, 'max_value' => 102, 'unit_label' => 'ml', 'point' => 'final'])
            ->assertCreated()->assertJsonPath('data.mandatory', true)->json('data.id');

        $this->postJson('/api/v1/quality-check-definitions', ['name' => 'Bad limits', 'type' => 'measure', 'min_value' => 5, 'max_value' => 1])->assertUnprocessable();
        $this->putJson("/api/v1/quality-check-definitions/{$id}", ['mandatory' => false])->assertOk()->assertJsonPath('data.mandatory', false);
        $this->getJson('/api/v1/quality-check-definitions?point=final')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/quality-check-definitions')->assertOk();
        $this->deleteJson("/api/v1/quality-check-definitions/{$id}")->assertForbidden();
        $this->actingAsRole('Sales Rep');
        $this->getJson('/api/v1/quality-alerts')->assertForbidden();
    }

    private function failedAlert(): QualityAlert
    {
        $check = app(\Modules\Manufacturing\Services\QualityService::class)->create($this->tenant->id, ['name' => 'Seal test', 'point' => 'final', 'production_order_id' => null]);
        app(\Modules\Manufacturing\Services\QualityService::class)->record($check, ['result' => 'failed'], ['actor_id' => null]);

        return QualityAlert::query()->where('quality_check_id', $check->id)->sole();
    }

    private function confirmed(): ProductionOrder
    {
        $id = $this->postJson('/api/v1/production-orders', [
            'bom_id' => $this->bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk();

        return ProductionOrder::findOrFail($id);
    }
}
