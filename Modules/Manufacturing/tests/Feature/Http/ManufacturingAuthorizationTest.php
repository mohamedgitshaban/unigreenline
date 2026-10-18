<?php

namespace Modules\Manufacturing\Tests\Feature\Http;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\MrpDemand;
use Modules\Manufacturing\Models\MrpRun;
use Modules\Manufacturing\Models\MrpSuggestion;
use Modules\Manufacturing\Models\ProductionOrder;
use Modules\Manufacturing\Models\ProductionScrap;
use Modules\Manufacturing\Models\ProductPlanning;
use Modules\Manufacturing\Models\QualityAlert;
use Modules\Manufacturing\Models\QualityCheck;
use Modules\Manufacturing\Models\QualityCheckDefinition;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\WorkCenter;
use Modules\Manufacturing\Models\WorkOrder;
use Modules\Manufacturing\Tests\ManufacturingTestCase;

class ManufacturingAuthorizationTest extends ManufacturingTestCase
{
    public function test_every_new_endpoint_refuses_another_companys_records(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);   // ~80 requests in one test
        $this->actingAsRole('Administrator');
        $other = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->recycle($other)->create();
        $bom = Bom::factory()->recycle($other)->create();
        $order = ProductionOrder::factory()->recycle($other)->create(['bom_id' => $bom->id, 'warehouse_id' => $warehouse->id]);
        $center = WorkCenter::factory()->recycle($other)->create();
        $routing = Routing::factory()->recycle($other)->create();
        $workOrder = WorkOrder::factory()->recycle($other)->create(['production_order_id' => $order->id, 'work_center_id' => $center->id]);
        $definition = QualityCheckDefinition::factory()->recycle($other)->create();
        $check = QualityCheck::create(['tenant_id' => $other->id, 'name' => 'x', 'point' => 'final', 'type' => 'pass_fail']);
        $alert = QualityAlert::create(['tenant_id' => $other->id, 'quality_check_id' => $check->id, 'description' => 'x']);
        $scrap = ProductionScrap::create(['tenant_id' => $other->id, 'number' => 'SCR1', 'production_order_id' => $order->id, 'kind' => 'finished', 'product_id' => $bom->product_id, 'qty_cartons' => 1, 'reason' => 'x', 'status' => 'pending']);
        $planning = ProductPlanning::factory()->recycle($other)->create();
        $demand = MrpDemand::factory()->recycle($other)->create(['warehouse_id' => $warehouse->id]);
        $run = MrpRun::create(['tenant_id' => $other->id, 'number' => 'MRP1', 'status' => 'completed', 'inputs' => []]);
        $suggestion = MrpSuggestion::create(['tenant_id' => $other->id, 'type' => 'purchase', 'product_id' => $bom->product_id, 'warehouse_id' => $warehouse->id, 'qty_cartons' => 1, 'start_date' => now(), 'due_date' => now(), 'fingerprint' => 'f', 'open_key' => 'f']);
        $revision = $bom->revisions()->create(['revision_no' => 1, 'snapshot' => []]);

        $requests = [
            ['GET', "boms/{$bom->id}/components"], ['GET', "boms/{$bom->id}/revisions"], ['GET', "boms/{$bom->id}/revisions/{$revision->id}"],
            ['POST', "boms/{$bom->id}/approve"], ['POST', "boms/{$bom->id}/archive"], ['POST', "boms/{$bom->id}/restore"], ['POST', "boms/{$bom->id}/revisions"],
            ...array_map(fn ($a) => ['POST', "production-orders/{$order->id}/{$a}"], ['confirm', 'plan', 'reserve-materials', 'release-materials', 'start', 'pause', 'resume', 'record-production', 'consume-materials', 'record-scrap', 'complete', 'cancel', 'reopen', 'create-backorder']),
            ['GET', "production-orders/{$order->id}/costs"], ['GET', "production-orders/{$order->id}/traceability"], ['GET', "production-orders/{$order->id}/history"],
            ['GET', "production-scraps/{$scrap->id}"], ['POST', "production-scraps/{$scrap->id}/approve"], ['POST', "production-scraps/{$scrap->id}/reject"],
            ['GET', "work-centers/{$center->id}"], ['PUT', "work-centers/{$center->id}"], ['DELETE', "work-centers/{$center->id}"], ['PUT', "work-centers/{$center->id}/calendar"],
            ['GET', "work-centers/{$center->id}/schedule"], ['GET', "work-centers/{$center->id}/downtimes"], ['POST', "work-centers/{$center->id}/downtimes"],
            ['GET', "routings/{$routing->id}"], ['PUT', "routings/{$routing->id}"], ['DELETE', "routings/{$routing->id}"], ['POST', "routings/{$routing->id}/operations"],
            ['GET', "work-orders/{$workOrder->id}"], ...array_map(fn ($a) => ['POST', "work-orders/{$workOrder->id}/{$a}"], ['start', 'pause', 'resume', 'complete', 'reschedule', 'assign']),
            ['GET', "quality-check-definitions/{$definition->id}"], ['PUT', "quality-check-definitions/{$definition->id}"], ['DELETE', "quality-check-definitions/{$definition->id}"],
            ['GET', "quality-checks/{$check->id}"], ['POST', "quality-checks/{$check->id}/record"], ['POST', "quality-alerts/{$alert->id}/resolve"],
            ['GET', "product-planning/{$planning->id}"], ['PUT', "product-planning/{$planning->id}"], ['DELETE', "product-planning/{$planning->id}"],
            ['GET', "mrp-demands/{$demand->id}"], ['PUT', "mrp-demands/{$demand->id}"], ['DELETE', "mrp-demands/{$demand->id}"],
            ['GET', "mrp/runs/{$run->id}"], ['GET', "mrp/suggestions/{$suggestion->id}"], ['POST', "mrp/suggestions/{$suggestion->id}/convert"], ['POST', "mrp/suggestions/{$suggestion->id}/dismiss"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}", [])->assertNotFound("{$method} {$uri} should be 404 for another company's record");
        }

        // The lists show only this company's records.
        foreach (['work-centers', 'routings', 'work-orders', 'quality-checks', 'quality-alerts', 'product-planning', 'mrp-demands', 'production-scraps', 'mrp/runs', 'mrp/suggestions', 'quality-check-definitions'] as $list) {
            $this->getJson("/api/v1/{$list}")->assertOk()->assertJsonCount(0, 'data');
        }

        // Another company's records cannot be pulled into this company's documents either.
        $product = $this->product('Mine');
        $this->postJson('/api/v1/boms', ['product_id' => $product->id, 'name' => 'X', 'routing_id' => $routing->id, 'lines' => [['component_product_id' => $product->id, 'qty_cartons' => 1]]])->assertUnprocessable();
        $this->postJson('/api/v1/mrp-demands', ['product_id' => $bom->product_id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 1, 'due_date' => now()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/v1/production-orders', ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id])->assertUnprocessable();
    }

    public function test_each_role_can_do_exactly_what_its_permissions_allow(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAsRole('Administrator');
        $product = $this->product('Tonic');
        $herb = $this->product('Herb');
        $bom = $this->bom($product, [[$herb, 1]], ['output_qty_cartons' => 10]);
        $orderId = $this->postJson('/api/v1/production-orders', ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10, 'exp_date' => now()->addYear()->toDateString()])->assertCreated()->json('data.id');
        $center = WorkCenter::factory()->recycle($this->tenant)->create();
        $check = QualityCheck::create(['tenant_id' => $this->tenant->id, 'name' => 'x', 'point' => 'final', 'type' => 'pass_fail']);

        // [method, uri, roles allowed]. Everything else must be 403.
        $matrix = [
            ['GET', 'boms', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor']],
            ['POST', "boms/{$bom->id}/approve", ['Administrator', 'Owner', 'Warehouse Manager']],
            ['POST', "boms/{$bom->id}/archive", ['Administrator']],
            ['DELETE', "boms/{$bom->id}", ['Administrator']],
            ['POST', "production-orders/{$orderId}/confirm", ['Administrator', 'Owner', 'Warehouse Manager']],
            ['POST', "production-orders/{$orderId}/plan", ['Administrator', 'Warehouse Manager', 'Warehouse Employee']],
            ['POST', "production-orders/{$orderId}/record-production", ['Administrator', 'Warehouse Manager', 'Warehouse Employee']],
            ['POST', "production-orders/{$orderId}/complete", ['Administrator', 'Owner', 'Warehouse Manager']],
            ['DELETE', "production-orders/{$orderId}", ['Administrator']],
            ['GET', "production-orders/{$orderId}/costs", ['Administrator', 'Owner', 'Auditor', 'Accountant']],
            ['GET', "production-orders/{$orderId}/history", ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor', 'Accountant']],
            ['GET', 'production-orders', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor', 'Accountant', 'Sales Manager']],
            ['POST', 'production-orders/from-sales-order', ['Administrator', 'Warehouse Manager', 'Warehouse Employee', 'Sales Manager']],
            ['PUT', 'manufacturing/settings', ['Administrator', 'Owner']],
            ['GET', 'manufacturing/settings', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor']],
            ['POST', 'work-centers', ['Administrator', 'Warehouse Manager', 'Warehouse Employee']],
            ['DELETE', "work-centers/{$center->id}", ['Administrator']],
            ['POST', "quality-checks/{$check->id}/record", ['Administrator', 'Warehouse Manager', 'Warehouse Employee']],
            ['POST', 'mrp/plan', ['Administrator', 'Warehouse Manager', 'Warehouse Employee']],
            ['GET', 'mrp/runs', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor', 'Purchasing']],
            // Converting also needs the right to create the document: only roles that can create purchase orders.
            ['POST', 'mrp/suggestions/convert-purchases', ['Administrator', 'Purchasing']],
            ['GET', 'reports/manufacturing/production', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor', 'Accountant']],
            ['GET', 'reports/manufacturing/cost-variance', ['Administrator', 'Owner', 'Auditor', 'Accountant']],
            ['GET', 'production-scraps', ['Administrator', 'Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor']],
        ];

        // Administrator goes last: some of what it is allowed to do (delete, archive) removes the records the others are tested against.
        $roles = ['Owner', 'Warehouse Manager', 'Warehouse Employee', 'Auditor', 'Accountant', 'Sales Manager', 'Sales Rep', 'Purchasing', 'Customer Service', 'HR Manager', 'Employee', 'Administrator'];

        foreach ($roles as $role) {
            $this->actingAsRole($role);

            foreach ($matrix as [$method, $uri, $allowed]) {
                $status = $this->json($method, "/api/v1/{$uri}", [])->status();

                in_array($role, $allowed, true)
                    ? $this->assertNotSame(403, $status, "{$role} should be able to {$method} {$uri}")
                    : $this->assertSame(403, $status, "{$role} should NOT be able to {$method} {$uri} (got {$status})");
            }
        }
    }

    public function test_cost_figures_are_visible_only_to_those_who_may_see_cost(): void
    {
        $this->actingAsRole('Administrator');
        $product = $this->product('Tonic');
        $bom = $this->bom($product, [[$this->product('Herb'), 1]], ['output_qty_cartons' => 10]);
        $id = $this->postJson('/api/v1/production-orders', ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id, 'qty_cartons' => 10])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/production-orders/{$id}/confirm")->assertOk()->assertJsonStructure(['data' => ['costs' => ['planned_material', 'capitalized']]]);

        $this->actingAsRole('Warehouse Manager');
        $order = $this->getJson("/api/v1/production-orders/{$id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('costs', $order);

        $this->actingAsRole('Accountant');
        $this->getJson("/api/v1/production-orders/{$id}")->assertOk()->assertJsonStructure(['data' => ['costs']]);
    }

    public function test_settings_are_validated_and_audited(): void
    {
        $this->actingAsRole('Administrator');

        $defaults = $this->getJson('/api/v1/manufacturing/settings')->assertOk()->json('data');
        $this->assertSame('MO', $defaults['mo_prefix']);
        $this->assertSame('on_confirm', $defaults['reservation_policy']);
        $this->assertFalse($defaults['wip_accounting']);
        $this->assertArrayHasKey('confirmed', $defaults['order_transitions']);

        $this->putJson('/api/v1/manufacturing/settings', ['mo_prefix' => 'bad prefix!'])->assertUnprocessable()->assertJsonValidationErrors('mo_prefix');
        $this->putJson('/api/v1/manufacturing/settings', ['number_padding' => 0])->assertUnprocessable();
        $this->putJson('/api/v1/manufacturing/settings', ['reservation_policy' => 'sometimes'])->assertUnprocessable();
        $this->putJson('/api/v1/manufacturing/settings', ['overhead_rate_pct' => 120])->assertUnprocessable();
        $this->putJson('/api/v1/manufacturing/settings', ['default_warehouse_id' => Warehouse::factory()->create()->id])->assertUnprocessable();   // another company's

        $this->putJson('/api/v1/manufacturing/settings', ['mo_prefix' => 'PRD', 'number_padding' => 4, 'wip_accounting' => true, 'overhead_rate_pct' => 5])
            ->assertOk()->assertJsonPath('data.mo_prefix', 'PRD')->assertJsonPath('data.overhead_rate_pct', '5.00');

        $this->assertDatabaseHas('audit_log', ['module' => 'manufacturing', 'entity_type' => 'ManufacturingSetting', 'operation' => 'UPDATE']);

        // The new prefix is used for the next order.
        $product = $this->product('Tonic');
        $bom = $this->bom($product, [[$this->product('Herb'), 1]]);
        $this->postJson('/api/v1/production-orders', ['bom_id' => $bom->id, 'warehouse_id' => $this->warehouse->id])->assertCreated()->assertJsonPath('data.number', 'PRD0001');

        // Settings belong to one company.
        $this->assertSame(1, ManufacturingSetting::query()->count());
    }
}
