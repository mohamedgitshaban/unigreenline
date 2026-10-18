<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;

/**
 * Production loss: material wasted (`component`, leaves stock) or output that
 * failed and was never received (`finished`).
 */
#[Fillable([
    'tenant_id', 'number', 'production_order_id', 'work_order_id', 'kind', 'product_id', 'qty_cartons', 'reason', 'status',
    'scrap_cost', 'journal_entry_id', 'requested_by', 'decided_by', 'decided_at', 'notes',
])]
class ProductionScrap extends Model
{
    use HasUlids;

    protected $attributes = ['status' => 'approved', 'scrap_cost' => 0];

    protected function casts(): array
    {
        return ['qty_cartons' => 'integer', 'scrap_cost' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
