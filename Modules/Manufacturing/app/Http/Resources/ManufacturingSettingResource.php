<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Support\OrderStateMachine;

class ManufacturingSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'mo_prefix' => $this->mo_prefix,
            'bom_prefix' => $this->bom_prefix,
            'work_order_prefix' => $this->work_order_prefix,
            'scrap_prefix' => $this->scrap_prefix,
            'mrp_prefix' => $this->mrp_prefix,
            'number_padding' => $this->number_padding,
            'default_warehouse_id' => $this->default_warehouse_id,
            'lead_time_days' => $this->lead_time_days,
            'default_operation_minutes' => $this->default_operation_minutes,
            'reservation_policy' => $this->reservation_policy,
            'consumption_policy' => $this->consumption_policy,
            'backorder_policy' => $this->backorder_policy,
            'allow_partial_production' => $this->allow_partial_production,
            'allow_over_consumption' => $this->allow_over_consumption,
            'scrap_requires_approval' => $this->scrap_requires_approval,
            'scrap_cost_policy' => $this->scrap_cost_policy,
            'require_bom_approval' => $this->require_bom_approval,
            'auto_create_work_orders' => $this->auto_create_work_orders,
            'allow_reopen_cancelled' => $this->allow_reopen_cancelled,
            'wip_accounting' => $this->wip_accounting,
            'overhead_rate_pct' => $this->overhead_rate_pct,
            'by_product_cost_method' => $this->by_product_cost_method,
            'cost_decimals' => $this->cost_decimals,
            'mrp_horizon_days' => $this->mrp_horizon_days,
            'cancel_unstarted_on_demand_cancel' => $this->cancel_unstarted_on_demand_cancel,
            'allowed_transitions' => $this->allowed_transitions,
            'order_transitions' => OrderStateMachine::TRANSITIONS,
        ];
    }
}
