<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\ComplaintFactory;
use Modules\Inventory\Models\Product;

#[Fillable([
    'tenant_id', 'customer_id', 'product_id', 'assigned_to', 'batch_no', 'type',
    'description', 'resolution', 'priority', 'status', 'complaint_date', 'resolved_date',
])]
class Complaint extends Model
{
    use HasFactory, HasUlids;

    /** Mirrors the DB defaults — see Customer::$attributes for why. */
    protected $attributes = [
        'priority' => 'medium',
        'status' => 'investigating',
    ];

    protected function casts(): array
    {
        return [
            'complaint_date' => 'date',
            'resolved_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): ComplaintFactory
    {
        return ComplaintFactory::new();
    }
}
