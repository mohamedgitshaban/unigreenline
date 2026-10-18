<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManufacturingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'mo_prefix' => ['sometimes', 'string', 'alpha_dash', 'max:10'],
            'bom_prefix' => ['sometimes', 'string', 'alpha_dash', 'max:10'],
            'work_order_prefix' => ['sometimes', 'string', 'alpha_dash', 'max:10'],
            'scrap_prefix' => ['sometimes', 'string', 'alpha_dash', 'max:10'],
            'mrp_prefix' => ['sometimes', 'string', 'alpha_dash', 'max:10'],
            'number_padding' => ['sometimes', 'integer', 'between:1,10'],

            'default_warehouse_id' => ['nullable', 'string', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'lead_time_days' => ['sometimes', 'integer', 'between:0,365'],
            'default_operation_minutes' => ['sometimes', 'integer', 'between:1,1440'],

            'reservation_policy' => ['sometimes', Rule::in(['on_confirm', 'manual'])],
            'consumption_policy' => ['sometimes', Rule::in(['backflush', 'manual'])],
            'backorder_policy' => ['sometimes', Rule::in(['ask', 'always', 'never'])],
            'allow_partial_production' => ['sometimes', 'boolean'],
            'allow_over_consumption' => ['sometimes', 'boolean'],
            'scrap_requires_approval' => ['sometimes', 'boolean'],
            'scrap_cost_policy' => ['sometimes', Rule::in(['expense', 'absorb'])],
            'require_bom_approval' => ['sometimes', 'boolean'],
            'auto_create_work_orders' => ['sometimes', 'boolean'],
            'allow_reopen_cancelled' => ['sometimes', 'boolean'],

            'wip_accounting' => ['sometimes', 'boolean'],
            'overhead_rate_pct' => ['sometimes', 'numeric', 'between:0,100'],
            'by_product_cost_method' => ['sometimes', Rule::in(['none', 'fixed_pct', 'sales_value'])],
            'cost_decimals' => ['sometimes', 'integer', 'between:0,2'],

            'mrp_horizon_days' => ['sometimes', 'integer', 'between:1,730'],
            'cancel_unstarted_on_demand_cancel' => ['sometimes', 'boolean'],

            'allowed_transitions' => ['nullable', 'array'],
            'allowed_transitions.*' => ['array'],
            'allowed_transitions.*.*' => ['string', Rule::in(['draft', 'confirmed', 'planned', 'in_progress', 'paused', 'completed', 'cancelled'])],
        ];
    }
}
