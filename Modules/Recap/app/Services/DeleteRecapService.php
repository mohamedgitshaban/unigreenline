<?php

namespace Modules\Recap\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Recap\Exceptions\RecapNotDraftException;
use Modules\Recap\Models\Recap;

/**
 * Deletes a draft recap (lines cascade). Approved ones are refused — undoing
 * a write-off needs a stock and journal reversal, not a delete — and rejected
 * ones stay as the record of the rejection.
 */
class DeleteRecapService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function delete(Recap $recap, array $actor = []): void
    {
        DB::transaction(function () use ($recap, $actor) {
            $locked = Recap::query()->whereKey($recap->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new RecapNotDraftException;
            }

            $locked->delete();

            $this->auditLog->record([
                'module' => 'recap',
                'entity_type' => 'Recap',
                'entity_id' => $locked->id,
                'operation' => 'DELETE',
                'user_id' => $actor['actor_id'] ?? null,
                'user_name' => $actor['actor_name'] ?? null,
                'tenant_id' => $locked->tenant_id,
                'warehouse_id' => $locked->warehouse_id,
                'prev_values' => ['reason' => $locked->reason, 'status' => $locked->status, 'total_value' => (string) $locked->total_value],
                'ip_address' => $actor['ip_address'] ?? null,
            ]);
        });
    }
}
