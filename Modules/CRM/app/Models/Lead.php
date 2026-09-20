<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\LeadFactory;

#[Fillable(['tenant_id', 'assigned_to', 'name', 'type', 'contact', 'phone', 'email', 'source', 'status', 'value', 'notes'])]
class Lead extends Model
{
    use HasFactory, HasUlids;

    /** Mirrors the DB default — see Customer::$attributes for why. */
    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
