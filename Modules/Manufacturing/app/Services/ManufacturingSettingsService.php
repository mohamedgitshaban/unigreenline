<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Support\OrderStateMachine;

class ManufacturingSettingsService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(string $tenantId, array $data, array $actor = []): ManufacturingSetting
    {
        if (isset($data['allowed_transitions'])) {
            OrderStateMachine::assertValidRestriction($data['allowed_transitions']);
        }

        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $settings = ManufacturingSetting::forTenant($tenantId);
            $settings->fill($data);
            $dirty = $settings->getDirty();
            $previous = array_intersect_key($settings->getOriginal(), $dirty);
            $settings->save();

            if ($dirty !== []) {
                $this->auditLog->record([
                    'module' => 'manufacturing',
                    'entity_type' => 'ManufacturingSetting',
                    'entity_id' => $settings->id,
                    'operation' => 'UPDATE',
                    'user_id' => $actor['actor_id'] ?? null,
                    'user_name' => $actor['actor_name'] ?? null,
                    'tenant_id' => $tenantId,
                    'prev_values' => array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $previous),
                    'new_values' => array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $dirty),
                    'ip_address' => $actor['ip_address'] ?? null,
                ]);
            }

            return $settings;
        });
    }
}
