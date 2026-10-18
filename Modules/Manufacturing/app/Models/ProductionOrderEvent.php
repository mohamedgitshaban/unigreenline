<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\User;

/**
 * One entry in an order's history. Append-only: it can be neither changed nor
 * deleted (except along with the order itself).
 */
#[Fillable(['tenant_id', 'production_order_id', 'action', 'from_status', 'to_status', 'user_id', 'payload'])]
class ProductionOrderEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order history cannot be changed.'));
        static::deleting(function (self $event) {
            if (ProductionOrder::query()->whereKey($event->production_order_id)->exists()) {
                throw new LogicException('Order history cannot be deleted.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
