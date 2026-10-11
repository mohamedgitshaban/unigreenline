<?php

namespace Modules\Recap\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Recap\Exceptions\RecapNotDraftException;
use Modules\Recap\Models\Recap;

/**
 * Edits a draft recap. `lines`, when given, replace every existing line.
 * Approved and rejected recaps are refused — an approved one already moved
 * stock and posted a journal entry.
 */
class UpdateRecapService
{
    public function __construct(
        private readonly CreateRecapService $createRecap,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{warehouse_id?: string, reason?: string, recap_date?: string, notes?: ?string, lines?: array<int, array{batch_id: string, qty_cartons: int}>}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(Recap $recap, array $data, array $actor = []): Recap
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        return DB::transaction(function () use ($recap, $data, $lines, $actor) {
            $locked = Recap::query()->whereKey($recap->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new RecapNotDraftException;
            }

            $locked->fill($data);
            $dirty = $locked->getDirty();
            $previous = array_intersect_key($locked->getOriginal(), $dirty);
            $locked->save();

            if ($lines !== null) {
                $this->createRecap->syncLines($locked, $lines);
            }

            $this->auditLog->record([
                'module' => 'recap',
                'entity_type' => 'Recap',
                'entity_id' => $locked->id,
                'operation' => 'UPDATE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => array_map('strval', $previous),
                'new_values' => array_map('strval', $dirty) + ($lines !== null ? ['lines' => count($lines).' replaced'] : []),
                'ip_address' => $actor['ip_address'] ?? null,
            ]);

            return $locked->fresh(['lines']);
        });
    }
}
