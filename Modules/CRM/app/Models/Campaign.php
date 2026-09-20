<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Database\Factories\CampaignFactory;

#[Fillable([
    'tenant_id', 'created_by', 'name', 'type', 'target', 'discount', 'start_date',
    'end_date', 'description', 'status', 'reach', 'revenue',
])]
class Campaign extends Model
{
    use HasFactory, HasUlids;

    /** Mirrors the DB defaults — see Customer::$attributes for why. */
    protected $attributes = [
        'status' => 'active',
        'reach' => 0,
        'revenue' => 0,
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'reach' => 'integer',
            'revenue' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
