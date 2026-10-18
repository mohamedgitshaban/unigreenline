<?php

use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\BomController;
use Modules\Manufacturing\Http\Controllers\ManufacturingAttachmentController;
use Modules\Manufacturing\Http\Controllers\ManufacturingReportController;
use Modules\Manufacturing\Http\Controllers\ManufacturingSettingsController;
use Modules\Manufacturing\Http\Controllers\MrpController;
use Modules\Manufacturing\Http\Controllers\MrpDemandController;
use Modules\Manufacturing\Http\Controllers\ProductionOrderController;
use Modules\Manufacturing\Http\Controllers\ProductionScrapController;
use Modules\Manufacturing\Http\Controllers\ProductPlanningController;
use Modules\Manufacturing\Http\Controllers\QualityAlertController;
use Modules\Manufacturing\Http\Controllers\QualityCheckController;
use Modules\Manufacturing\Http\Controllers\QualityCheckDefinitionController;
use Modules\Manufacturing\Http\Controllers\RoutingController;
use Modules\Manufacturing\Http\Controllers\WorkCenterController;
use Modules\Manufacturing\Http\Controllers\WorkOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('manufacturing/settings', [ManufacturingSettingsController::class, 'show'])->can('manufacturing-settings.view');
    Route::put('manufacturing/settings', [ManufacturingSettingsController::class, 'update'])->can('manufacturing-settings.update');

    Route::get('boms', [BomController::class, 'index'])->can('boms.view')->exportable('boms.export');
    Route::post('boms', [BomController::class, 'store'])->can('boms.create');
    Route::get('boms/{bom}', [BomController::class, 'show'])->can('boms.view');
    Route::put('boms/{bom}', [BomController::class, 'update'])->can('boms.update');
    Route::delete('boms/{bom}', [BomController::class, 'destroy'])->can('boms.delete');
    Route::post('boms/{bom}/approve', [BomController::class, 'approve'])->can('boms.approve');
    Route::post('boms/{bom}/archive', [BomController::class, 'archive'])->can('boms.archive');
    Route::post('boms/{bom}/restore', [BomController::class, 'restore'])->can('boms.restore');
    Route::get('boms/{bom}/components', [BomController::class, 'components'])->can('boms.view');
    Route::get('boms/{bom}/revisions', [BomController::class, 'revisions'])->can('boms.view');
    Route::get('boms/{bom}/revisions/{revision}', [BomController::class, 'revision'])->can('boms.view');
    Route::post('boms/{bom}/revisions', [BomController::class, 'createRevision'])->can('boms.revise');

    Route::get('production-orders', [ProductionOrderController::class, 'index'])->can('production-orders.view')->exportable('production-orders.export');
    Route::post('production-orders', [ProductionOrderController::class, 'store'])->can('production-orders.create');
    Route::post('production-orders/from-sales-order', [ProductionOrderController::class, 'fromSalesOrder'])->can('production-orders.from-sales-order');
    Route::get('production-orders/{productionOrder}', [ProductionOrderController::class, 'show'])->can('production-orders.view');
    Route::put('production-orders/{productionOrder}', [ProductionOrderController::class, 'update'])->can('production-orders.update');
    Route::delete('production-orders/{productionOrder}', [ProductionOrderController::class, 'destroy'])->can('production-orders.delete');
    Route::post('production-orders/{productionOrder}/complete', [ProductionOrderController::class, 'complete'])->can('production-orders.complete');
    Route::post('production-orders/{productionOrder}/cancel', [ProductionOrderController::class, 'cancel'])->can('production-orders.cancel');
    Route::post('production-orders/{productionOrder}/confirm', [ProductionOrderController::class, 'confirm'])->can('production-orders.confirm');
    Route::post('production-orders/{productionOrder}/plan', [ProductionOrderController::class, 'plan'])->can('production-orders.plan');
    Route::post('production-orders/{productionOrder}/reserve-materials', [ProductionOrderController::class, 'reserveMaterials'])->can('production-orders.reserve-materials');
    Route::post('production-orders/{productionOrder}/release-materials', [ProductionOrderController::class, 'releaseMaterials'])->can('production-orders.release-materials');
    Route::post('production-orders/{productionOrder}/start', [ProductionOrderController::class, 'start'])->can('production-orders.start');
    Route::post('production-orders/{productionOrder}/pause', [ProductionOrderController::class, 'pause'])->can('production-orders.pause');
    Route::post('production-orders/{productionOrder}/resume', [ProductionOrderController::class, 'resume'])->can('production-orders.resume');
    Route::post('production-orders/{productionOrder}/record-production', [ProductionOrderController::class, 'recordProduction'])->can('production-orders.record-production');
    Route::post('production-orders/{productionOrder}/consume-materials', [ProductionOrderController::class, 'consumeMaterials'])->can('production-orders.consume-materials');
    Route::post('production-orders/{productionOrder}/record-scrap', [ProductionOrderController::class, 'recordScrap'])->can('production-orders.record-scrap');
    Route::post('production-orders/{productionOrder}/reopen', [ProductionOrderController::class, 'reopen'])->can('production-orders.reopen');
    Route::post('production-orders/{productionOrder}/create-backorder', [ProductionOrderController::class, 'createBackorder'])->can('production-orders.create-backorder');
    Route::get('production-orders/{productionOrder}/costs', [ProductionOrderController::class, 'costs'])->can('production-orders.costs');
    Route::get('production-orders/{productionOrder}/traceability', [ProductionOrderController::class, 'traceability'])->can('production-orders.traceability');
    Route::get('production-orders/{productionOrder}/history', [ProductionOrderController::class, 'history'])->can('production-orders.history');

    Route::get('production-scraps', [ProductionScrapController::class, 'index'])->can('production-scraps.view')->exportable('production-scraps.export');
    Route::get('production-scraps/{productionScrap}', [ProductionScrapController::class, 'show'])->can('production-scraps.view');
    Route::post('production-scraps/{productionScrap}/approve', [ProductionScrapController::class, 'approve'])->can('production-scraps.approve');
    Route::post('production-scraps/{productionScrap}/reject', [ProductionScrapController::class, 'reject'])->can('production-scraps.reject');

    Route::get('work-centers', [WorkCenterController::class, 'index'])->can('work-centers.view')->exportable('work-centers.export');
    Route::post('work-centers', [WorkCenterController::class, 'store'])->can('work-centers.create');
    Route::get('work-centers/{workCenter}', [WorkCenterController::class, 'show'])->can('work-centers.view');
    Route::put('work-centers/{workCenter}', [WorkCenterController::class, 'update'])->can('work-centers.update');
    Route::delete('work-centers/{workCenter}', [WorkCenterController::class, 'destroy'])->can('work-centers.delete');
    Route::put('work-centers/{workCenter}/calendar', [WorkCenterController::class, 'updateCalendar'])->can('work-centers.update-calendar');
    Route::get('work-centers/{workCenter}/schedule', [WorkCenterController::class, 'schedule'])->can('work-centers.schedule');
    Route::get('work-centers/{workCenter}/downtimes', [WorkCenterController::class, 'downtimes'])->can('work-center-downtimes.view');
    Route::post('work-centers/{workCenter}/downtimes', [WorkCenterController::class, 'storeDowntime'])->can('work-center-downtimes.create');
    Route::delete('work-centers/{workCenter}/downtimes/{downtime}', [WorkCenterController::class, 'destroyDowntime'])->can('work-center-downtimes.delete');

    Route::get('routings', [RoutingController::class, 'index'])->can('routings.view')->exportable('routings.export');
    Route::post('routings', [RoutingController::class, 'store'])->can('routings.create');
    Route::get('routings/{routing}', [RoutingController::class, 'show'])->can('routings.view');
    Route::put('routings/{routing}', [RoutingController::class, 'update'])->can('routings.update');
    Route::delete('routings/{routing}', [RoutingController::class, 'destroy'])->can('routings.delete');
    Route::post('routings/{routing}/operations', [RoutingController::class, 'storeOperation'])->can('routing-operations.create');
    Route::put('routings/{routing}/operations/{operation}', [RoutingController::class, 'updateOperation'])->can('routing-operations.update');
    Route::delete('routings/{routing}/operations/{operation}', [RoutingController::class, 'destroyOperation'])->can('routing-operations.delete');

    Route::get('work-orders', [WorkOrderController::class, 'index'])->can('work-orders.view')->exportable('work-orders.export');
    Route::get('work-orders/{workOrder}', [WorkOrderController::class, 'show'])->can('work-orders.view');
    Route::post('work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->can('work-orders.start');
    Route::post('work-orders/{workOrder}/pause', [WorkOrderController::class, 'pause'])->can('work-orders.pause');
    Route::post('work-orders/{workOrder}/resume', [WorkOrderController::class, 'resume'])->can('work-orders.resume');
    Route::post('work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->can('work-orders.complete');
    Route::post('work-orders/{workOrder}/reschedule', [WorkOrderController::class, 'reschedule'])->can('work-orders.reschedule');
    Route::post('work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign'])->can('work-orders.assign');

    Route::get('quality-check-definitions', [QualityCheckDefinitionController::class, 'index'])->can('quality-check-definitions.view');
    Route::post('quality-check-definitions', [QualityCheckDefinitionController::class, 'store'])->can('quality-check-definitions.create');
    Route::get('quality-check-definitions/{definition}', [QualityCheckDefinitionController::class, 'show'])->can('quality-check-definitions.view');
    Route::put('quality-check-definitions/{definition}', [QualityCheckDefinitionController::class, 'update'])->can('quality-check-definitions.update');
    Route::delete('quality-check-definitions/{definition}', [QualityCheckDefinitionController::class, 'destroy'])->can('quality-check-definitions.delete');
    Route::get('quality-checks', [QualityCheckController::class, 'index'])->can('quality-checks.view');
    Route::post('quality-checks', [QualityCheckController::class, 'store'])->can('quality-checks.create');
    Route::get('quality-checks/{qualityCheck}', [QualityCheckController::class, 'show'])->can('quality-checks.view');
    Route::post('quality-checks/{qualityCheck}/record', [QualityCheckController::class, 'record'])->can('quality-checks.record');
    Route::get('quality-alerts', [QualityAlertController::class, 'index'])->can('quality-alerts.view');
    Route::post('quality-alerts/{qualityAlert}/resolve', [QualityAlertController::class, 'resolve'])->can('quality-alerts.resolve');

    Route::get('product-planning', [ProductPlanningController::class, 'index'])->can('product-planning.view')->exportable('product-planning.export');
    Route::post('product-planning', [ProductPlanningController::class, 'store'])->can('product-planning.create');
    Route::get('product-planning/{planning}', [ProductPlanningController::class, 'show'])->can('product-planning.view');
    Route::put('product-planning/{planning}', [ProductPlanningController::class, 'update'])->can('product-planning.update');
    Route::delete('product-planning/{planning}', [ProductPlanningController::class, 'destroy'])->can('product-planning.delete');

    Route::get('mrp-demands', [MrpDemandController::class, 'index'])->can('mrp-demands.view')->exportable('mrp-demands.export');
    Route::post('mrp-demands', [MrpDemandController::class, 'store'])->can('mrp-demands.create');
    Route::get('mrp-demands/{demand}', [MrpDemandController::class, 'show'])->can('mrp-demands.view');
    Route::put('mrp-demands/{demand}', [MrpDemandController::class, 'update'])->can('mrp-demands.update');
    Route::delete('mrp-demands/{demand}', [MrpDemandController::class, 'destroy'])->can('mrp-demands.delete');

    Route::post('mrp/plan', [MrpController::class, 'plan'])->can('mrp-runs.run');
    Route::get('mrp/runs', [MrpController::class, 'runs'])->can('mrp-runs.view');
    Route::get('mrp/runs/{run}', [MrpController::class, 'showRun'])->can('mrp-runs.view');
    Route::get('mrp/requirements', [MrpController::class, 'requirements'])->can('mrp-requirements.view');
    Route::get('mrp/shortages', [MrpController::class, 'shortages'])->can('mrp-shortages.view');
    Route::get('mrp/suggestions', [MrpController::class, 'suggestions'])->can('mrp-suggestions.view')->exportable('mrp-suggestions.export');
    Route::post('mrp/suggestions/convert-purchases', [MrpController::class, 'convertPurchases'])->can('mrp-suggestions.convert');
    Route::get('mrp/suggestions/{suggestion}', [MrpController::class, 'showSuggestion'])->can('mrp-suggestions.view');
    Route::post('mrp/suggestions/{suggestion}/convert', [MrpController::class, 'convert'])->can('mrp-suggestions.convert');
    Route::post('mrp/suggestions/{suggestion}/dismiss', [MrpController::class, 'dismiss'])->can('mrp-suggestions.dismiss');

    Route::get('reports/manufacturing/production', [ManufacturingReportController::class, 'production'])->can('manufacturing-reports.view')->exportable('manufacturing-reports.export');
    Route::get('reports/manufacturing/material-consumption', [ManufacturingReportController::class, 'materialConsumption'])->can('manufacturing-reports.view')->exportable('manufacturing-reports.export');
    Route::get('reports/manufacturing/scrap', [ManufacturingReportController::class, 'scrap'])->can('manufacturing-reports.view')->exportable('manufacturing-reports.export');
    Route::get('reports/manufacturing/work-center-utilization', [ManufacturingReportController::class, 'workCenterUtilization'])->can('manufacturing-reports.view')->exportable('manufacturing-reports.export');
    Route::get('reports/manufacturing/lead-times', [ManufacturingReportController::class, 'leadTimes'])->can('manufacturing-reports.view')->exportable('manufacturing-reports.export');
    Route::get('reports/manufacturing/traceability', [ManufacturingReportController::class, 'traceability'])->can('manufacturing-reports.view');
    Route::get('reports/manufacturing/cost-variance', [ManufacturingReportController::class, 'costVariance'])->can('manufacturing-cost-reports.view')->exportable('manufacturing-cost-reports.export');
    Route::get('reports/manufacturing/wip-valuation', [ManufacturingReportController::class, 'wipValuation'])->can('manufacturing-cost-reports.view')->exportable('manufacturing-cost-reports.export');

    Route::get('manufacturing/attachments', [ManufacturingAttachmentController::class, 'index'])->can('manufacturing-attachments.view');
    Route::post('manufacturing/attachments', [ManufacturingAttachmentController::class, 'store'])->can('manufacturing-attachments.create');
    Route::get('manufacturing/attachments/{attachment}/download', [ManufacturingAttachmentController::class, 'download'])->can('manufacturing-attachments.view');
    Route::delete('manufacturing/attachments/{attachment}', [ManufacturingAttachmentController::class, 'destroy'])->can('manufacturing-attachments.delete');
});
