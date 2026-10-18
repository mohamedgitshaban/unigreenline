<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Database\Factories\ProductionOrderFactory;

/**
 * One manufacturing job: `runs` repetitions of a BOM in a warehouse. Draft
 * until completed; completing consumes the components (FEFO), creates the
 * finished batch and posts the cost to the ledger.
 */
#[Fillable([
    'tenant_id', 'number', 'bom_id', 'bom_revision_id', 'parent_order_id', 'warehouse_id', 'status', 'priority', 'reservation_status',
    'runs', 'output_qty_cartons', 'produced_qty_cartons', 'rejected_qty_cartons', 'production_date',
    'planned_start', 'planned_finish', 'actual_start', 'actual_finish', 'assigned_to', 'source_type', 'source_id', 'cancellation_reason',
    'batch_no', 'mfg_date', 'exp_date', 'conversion_cost', 'material_cost', 'cost_per_carton',
    'planned_material_cost', 'planned_operation_cost', 'planned_overhead_cost', 'actual_operation_cost', 'actual_overhead_cost',
    'scrap_cost', 'capitalized_cost', 'capitalized_material', 'variance_cost', 'wip_accounting', 'variance_journal_entry_id',
    'output_batch_id', 'journal_entry_id', 'notes', 'created_by', 'completed_by', 'completed_at',
])]
class ProductionOrder extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'draft',
        'priority' => 1,
        'reservation_status' => 'none',
        'runs' => 1,
        'produced_qty_cartons' => 0,
        'rejected_qty_cartons' => 0,
        'conversion_cost' => 0,
        'material_cost' => 0,
        'cost_per_carton' => 0,
        'planned_material_cost' => 0,
        'planned_operation_cost' => 0,
        'planned_overhead_cost' => 0,
        'actual_operation_cost' => 0,
        'actual_overhead_cost' => 0,
        'scrap_cost' => 0,
        'capitalized_cost' => 0,
        'capitalized_material' => 0,
        'variance_cost' => 0,
        'wip_accounting' => false,
    ];

    protected function casts(): array
    {
        return [
            'runs' => 'integer',
            'priority' => 'integer',
            'output_qty_cartons' => 'integer',
            'produced_qty_cartons' => 'integer',
            'rejected_qty_cartons' => 'integer',
            'planned_start' => 'datetime',
            'planned_finish' => 'datetime',
            'actual_start' => 'datetime',
            'actual_finish' => 'datetime',
            'planned_material_cost' => 'decimal:2',
            'planned_operation_cost' => 'decimal:2',
            'planned_overhead_cost' => 'decimal:2',
            'actual_operation_cost' => 'decimal:2',
            'actual_overhead_cost' => 'decimal:2',
            'scrap_cost' => 'decimal:2',
            'capitalized_cost' => 'decimal:2',
            'capitalized_material' => 'decimal:2',
            'variance_cost' => 'decimal:2',
            'wip_accounting' => 'boolean',
            'production_date' => 'date',
            'mfg_date' => 'date',
            'exp_date' => 'date',
            'conversion_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'cost_per_carton' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Cartons still to be made: planned less what has been received or rejected.
     */
    public function remainingQty(): int
    {
        return max(0, $this->output_qty_cartons - $this->produced_qty_cartons - $this->rejected_qty_cartons);
    }

    /**
     * Whether any stock has moved for this order (material issued or output received).
     */
    public function hasMovedStock(): bool
    {
        return $this->produced_qty_cartons > 0
            || $this->rejected_qty_cartons > 0
            || $this->consumptions()->exists();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function outputBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'output_batch_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }

    public function bomRevision(): BelongsTo
    {
        return $this->belongsTo(BomRevision::class, 'bom_revision_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function backorders(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductionOrderComponent::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class);
    }

    public function scraps(): HasMany
    {
        return $this->hasMany(ProductionScrap::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionOrderEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->orderBy('sequence');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected static function newFactory(): ProductionOrderFactory
    {
        return ProductionOrderFactory::new();
    }
}
