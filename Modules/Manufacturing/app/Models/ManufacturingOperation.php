<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Remembers the outcome of a request that carried an idempotency key, so a
 * client retry gets the original answer instead of repeating the work.
 */
#[Fillable(['tenant_id', 'production_order_id', 'action', 'idempotency_key', 'result'])]
class ManufacturingOperation extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
