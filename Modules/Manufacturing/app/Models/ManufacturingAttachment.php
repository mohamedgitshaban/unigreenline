<?php

namespace Modules\Manufacturing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;

/**
 * A file attached to a manufacturing record (a BoM, order, work order, routing or quality check).
 */
#[Fillable(['tenant_id', 'attachable_type', 'attachable_id', 'path', 'original_name', 'mime_type', 'size', 'description', 'uploaded_by'])]
class ManufacturingAttachment extends Model
{
    use HasUlids;

    /** What a file can be attached to: type name => model. */
    public const OWNERS = [
        'bom' => Bom::class,
        'production_order' => ProductionOrder::class,
        'work_order' => WorkOrder::class,
        'routing' => Routing::class,
        'quality_check' => QualityCheck::class,
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public static function directory(string $tenantId): string
    {
        return "manufacturing-attachments/{$tenantId}";
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
