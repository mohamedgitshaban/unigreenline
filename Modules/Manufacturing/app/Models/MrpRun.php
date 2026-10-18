<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;

#[Fillable(['tenant_id', 'number', 'status', 'inputs', 'stats', 'error', 'started_at', 'finished_at', 'requested_by'])]
class MrpRun extends Model
{
    use HasUlids;

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MrpRunLine::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(MrpSuggestion::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
