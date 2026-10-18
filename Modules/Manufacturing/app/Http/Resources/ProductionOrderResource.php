<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Services\WorkOrderService;

class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = $request->user()?->can('production-orders.costs') ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'bom' => $this->bom ? [
                'id' => $this->bom->id, 'code' => $this->bom->code, 'name' => $this->bom->name, 'product_id' => $this->bom->product_id,
                'revision' => $this->whenLoaded('bomRevision', fn () => $this->bomRevision?->revision_no),
            ] : null,
            'product' => $this->bom?->relationLoaded('product') && $this->bom->product ? ['id' => $this->bom->product->id, 'name' => $this->bom->product->name, 'sku' => $this->bom->product->sku] : null,
            'warehouse' => $this->warehouse ? ['id' => $this->warehouse->id, 'name' => $this->warehouse->name] : null,
            'status' => $this->status,
            'priority' => $this->priority,
            'reservation_status' => $this->reservation_status,
            'runs' => $this->runs,
            'output_qty_cartons' => $this->output_qty_cartons,
            'produced_qty_cartons' => $this->produced_qty_cartons,
            'rejected_qty_cartons' => $this->rejected_qty_cartons,
            'remaining_qty_cartons' => $this->remainingQty(),
            'progress_pct' => $this->whenLoaded('workOrders', fn () => app(WorkOrderService::class)->progress($this->resource)),
            'production_date' => $this->production_date->toDateString(),
            'planned_start' => $this->planned_start,
            'planned_finish' => $this->planned_finish,
            'actual_start' => $this->actual_start,
            'actual_finish' => $this->actual_finish,
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'parent_order_id' => $this->parent_order_id,
            'cancellation_reason' => $this->cancellation_reason,
            'batch_no' => $this->batch_no,
            'mfg_date' => $this->mfg_date?->toDateString(),
            'exp_date' => $this->exp_date?->toDateString(),
            'conversion_cost' => $this->conversion_cost,
            'material_cost' => $this->material_cost,
            'cost_per_carton' => $this->cost_per_carton,
            'costs' => $this->when($canSeeCosts, fn () => [
                'planned_material' => $this->planned_material_cost,
                'planned_operation' => $this->planned_operation_cost,
                'planned_overhead' => $this->planned_overhead_cost,
                'actual_material' => $this->material_cost,
                'actual_operation' => $this->actual_operation_cost,
                'actual_overhead' => $this->actual_overhead_cost,
                'scrap' => $this->scrap_cost,
                'capitalized' => $this->capitalized_cost,
                'variance_written_off' => $this->variance_cost,
                'wip_accounting' => $this->wip_accounting,
            ]),
            'output_batch_id' => $this->output_batch_id,
            'journal_entry_id' => $this->journal_entry_id,
            'notes' => $this->notes,
            'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($c) => [
                'id' => $c->id,
                'product' => $c->relationLoaded('product') && $c->product ? ['id' => $c->product->id, 'name' => $c->product->name, 'sku' => $c->product->sku] : null,
                'product_id' => $c->product_id,
                'required_qty' => $c->required_qty,
                'required_cartons' => $c->required_cartons,
                'reserved_cartons' => $c->reserved_cartons,
                'consumed_cartons' => $c->consumed_cartons,
                'scrap_pct' => $c->scrap_pct,
                'optional' => $c->optional,
                'work_order_id' => $c->work_order_id,
            ])->values()),
            'work_orders' => $this->whenLoaded('workOrders', fn () => $this->workOrders->map(fn ($w) => [
                'id' => $w->id,
                'number' => $w->number,
                'sequence' => $w->sequence,
                'name' => $w->name,
                'status' => $w->status,
                'work_center_id' => $w->work_center_id,
                'planned_minutes' => $w->planned_minutes,
                'actual_minutes' => $w->actual_minutes,
                'scheduled_start' => $w->scheduled_start,
                'scheduled_end' => $w->scheduled_end,
                'qty_processed' => $w->qty_processed,
                'qty_rejected' => $w->qty_rejected,
            ])->values()),
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs->map(fn ($o) => [
                'id' => $o->id,
                'kind' => $o->kind,
                'product_id' => $o->product_id,
                'batch_id' => $o->batch_id,
                'batch_no' => $o->batch_no,
                'qty_cartons' => $o->qty_cartons,
                'cost_per_carton' => $this->when($canSeeCosts, $o->cost_per_carton),
                'line_value' => $this->when($canSeeCosts, $o->line_value),
                'created_at' => $o->created_at,
            ])->values()),
            'consumptions' => $this->whenLoaded('consumptions', fn () => $this->consumptions->map(fn ($c) => [
                'product_id' => $c->product_id,
                'kind' => $c->kind,
                'batch_id' => $c->batch_id,
                'batch_no' => $c->batch_no,
                'qty_cartons' => $c->qty_cartons,
                'cost_per_carton' => $c->cost_per_carton,
                'line_value' => $c->line_value,
            ])->values()),
            'created_by' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'completed_by' => $this->completedBy ? ['id' => $this->completedBy->id, 'name' => $this->completedBy->name] : null,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }
}
