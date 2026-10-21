<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\CustomerCallFactory;
use Modules\CRM\Models\Concerns\VisibleToTeam;

/**
 * A phone call a rep made to (or took from) a customer, with what was said.
 */
#[Fillable(['tenant_id', 'customer_id', 'rep_id', 'called_at', 'direction', 'outcome', 'duration_seconds', 'subject', 'message', 'next_follow_up'])]
class CustomerCall extends Model
{
    use HasFactory, HasUlids, VisibleToTeam;

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'next_follow_up' => 'date',
            'duration_seconds' => 'integer',
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

    protected static function newFactory(): CustomerCallFactory
    {
        return CustomerCallFactory::new();
    }
}
