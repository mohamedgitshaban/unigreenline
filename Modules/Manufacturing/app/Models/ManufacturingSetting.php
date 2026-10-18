<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Warehouse;

/**
 * A company's manufacturing configuration. Read through forTenant(), which
 * creates the row with the defaults the first time.
 */
#[Fillable([
    'tenant_id', 'mo_prefix', 'bom_prefix', 'work_order_prefix', 'scrap_prefix', 'mrp_prefix', 'number_padding',
    'default_warehouse_id', 'lead_time_days', 'default_operation_minutes',
    'reservation_policy', 'consumption_policy', 'backorder_policy', 'allow_partial_production', 'allow_over_consumption',
    'scrap_requires_approval', 'scrap_cost_policy', 'require_bom_approval', 'auto_create_work_orders', 'allow_reopen_cancelled',
    'wip_accounting', 'overhead_rate_pct', 'by_product_cost_method', 'cost_decimals',
    'mrp_horizon_days', 'cancel_unstarted_on_demand_cancel', 'allowed_transitions',
])]
class ManufacturingSetting extends Model
{
    use HasUlids;

    /** Mirrors the table defaults, so a freshly created row is complete without a re-read. */
    protected $attributes = [
        'mo_prefix' => 'MO', 'bom_prefix' => 'BOM', 'work_order_prefix' => 'WO', 'scrap_prefix' => 'SCR', 'mrp_prefix' => 'MRP',
        'number_padding' => 5, 'lead_time_days' => 1, 'default_operation_minutes' => 60,
        'reservation_policy' => 'on_confirm', 'consumption_policy' => 'backflush', 'backorder_policy' => 'ask',
        'allow_partial_production' => true, 'allow_over_consumption' => false, 'scrap_requires_approval' => false,
        'scrap_cost_policy' => 'expense', 'require_bom_approval' => false, 'auto_create_work_orders' => true,
        'allow_reopen_cancelled' => false, 'wip_accounting' => false, 'overhead_rate_pct' => 0,
        'by_product_cost_method' => 'fixed_pct', 'cost_decimals' => 2, 'mrp_horizon_days' => 30,
        'cancel_unstarted_on_demand_cancel' => false,
    ];

    protected function casts(): array
    {
        return [
            'number_padding' => 'integer',
            'lead_time_days' => 'integer',
            'default_operation_minutes' => 'integer',
            'allow_partial_production' => 'boolean',
            'allow_over_consumption' => 'boolean',
            'scrap_requires_approval' => 'boolean',
            'require_bom_approval' => 'boolean',
            'auto_create_work_orders' => 'boolean',
            'allow_reopen_cancelled' => 'boolean',
            'wip_accounting' => 'boolean',
            'overhead_rate_pct' => 'decimal:2',
            'cost_decimals' => 'integer',
            'mrp_horizon_days' => 'integer',
            'cancel_unstarted_on_demand_cancel' => 'boolean',
            'allowed_transitions' => 'array',
        ];
    }

    public static function forTenant(string $tenantId): self
    {
        self::query()->firstOrCreate(['tenant_id' => $tenantId]);

        // A fresh read, so the model doesn't carry "just created" (which would turn a 200 into a 201).
        return self::query()->where('tenant_id', $tenantId)->firstOrFail();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}
