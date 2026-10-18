<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Models\User;

/**
 * An immutable snapshot of a recipe at one revision. Once written it can never
 * be changed or removed (other than by deleting the BoM itself), so what an
 * order was planned against can always be shown.
 */
#[Fillable(['bom_id', 'revision_no', 'snapshot', 'change_note', 'created_by'])]
class BomRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['revision_no' => 'integer', 'snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A BoM revision cannot be changed.'));
        static::deleting(function (self $revision) {
            // Only the cascade from deleting the BoM itself may remove it.
            if (Bom::query()->whereKey($revision->bom_id)->exists()) {
                throw new LogicException('A BoM revision cannot be deleted.');
            }
        });
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
