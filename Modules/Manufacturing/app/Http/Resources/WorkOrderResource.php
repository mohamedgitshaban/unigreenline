<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeCosts = $request->user()?->can('production-orders.costs') ?? false;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'production_order' => $this->whenLoaded('productionOrder', fn () => ['id' => $this->productionOrder->id, 'number' => $this->productionOrder->number, 'status' => $this->productionOrder->status]),
            'sequence' => $this->sequence,
            'name' => $this->name,
            'status' => $this->status,
            'work_center' => $this->whenLoaded('workCenter', fn () => ['id' => $this->workCenter->id, 'code' => $this->workCenter->code, 'name' => $this->workCenter->name]),
            'work_center_id' => $this->work_center_id,
            'depends_on_id' => $this->depends_on_id,
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'qty_planned' => $this->qty_planned,
            'qty_processed' => $this->qty_processed,
            'qty_rejected' => $this->qty_rejected,
            'planned_minutes' => $this->planned_minutes,
            'actual_minutes' => $this->actual_minutes,
            'duration_variance_minutes' => $this->status === 'completed' ? $this->actual_minutes - $this->planned_minutes : null,
            'scheduled_start' => $this->scheduled_start,
            'scheduled_end' => $this->scheduled_end,
            'actual_start' => $this->actual_start,
            'actual_end' => $this->actual_end,
            'instructions' => $this->instructions,
            'notes' => $this->notes,
            'failure_reason' => $this->failure_reason,
            'labor_cost' => $this->when($canSeeCosts, $this->labor_cost),
            'machine_cost' => $this->when($canSeeCosts, $this->machine_cost),
            'time_logs' => $this->whenLoaded('timeLogs', fn () => $this->timeLogs->map(fn ($l) => [
                'started_at' => $l->started_at, 'ended_at' => $l->ended_at, 'minutes' => $l->minutes, 'user_id' => $l->user_id,
            ])->values()),
            'quality_checks' => $this->whenLoaded('qualityChecks', fn () => $this->qualityChecks->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'mandatory' => $c->mandatory,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
