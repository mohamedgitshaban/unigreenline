<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\CustomerVisitFactory;

#[Fillable(['tenant_id', 'customer_id', 'rep_id', 'visit_date', 'type', 'outcome', 'notes', 'next_visit'])]
class CustomerVisit extends Model
{
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'next_visit' => 'date',
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

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    protected static function newFactory(): CustomerVisitFactory
    {
        return CustomerVisitFactory::new();
    }
}
