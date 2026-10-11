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
    'tenant_id', 'bom_id', 'warehouse_id', 'status', 'runs', 'output_qty_cartons', 'production_date',
    'batch_no', 'mfg_date', 'exp_date', 'conversion_cost', 'material_cost', 'cost_per_carton',
    'output_batch_id', 'journal_entry_id', 'notes', 'created_by', 'completed_by', 'completed_at',
])]
class ProductionOrder extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'draft',
        'runs' => 1,
        'conversion_cost' => 0,
        'material_cost' => 0,
        'cost_per_carton' => 0,
    ];

    protected function casts(): array
    {
        return [
            'runs' => 'integer',
            'output_qty_cartons' => 'integer',
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
