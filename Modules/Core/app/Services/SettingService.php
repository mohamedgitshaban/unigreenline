<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\TenantSetting;

class SettingService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Saves the given fields; images that are replaced or cleared are deleted
     * from storage unless another field still points at them.
     *
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(string $tenantId, array $data, array $actor = []): TenantSetting
    {
        [$settings, $replacedImages] = DB::transaction(function () use ($tenantId, $data, $actor) {
            $settings = TenantSetting::forTenant($tenantId);
            $previousImages = array_filter($settings->only(TenantSetting::IMAGE_COLUMNS));

            $settings->fill($data);
            $dirty = $settings->getDirty();
            $previous = array_intersect_key($settings->getOriginal(), $dirty);
            $isNew = ! $settings->exists;
            $settings->save();

            if ($dirty !== []) {
                $this->auditLog->record([
                    'module' => 'admin',
                    'entity_type' => 'TenantSetting',
                    'entity_id' => $settings->id,
                    'operation' => $isNew ? 'INSERT' : 'UPDATE',
                    'user_id' => $actor['actor_id'] ?? null,
                    'user_name' => $actor['actor_name'] ?? null,
                    'tenant_id' => $tenantId,
                    'prev_values' => $isNew ? null : $previous,
                    'new_values' => $dirty,
                    'ip_address' => $actor['ip_address'] ?? null,
                ]);
            }

            return [$settings, array_diff($previousImages, array_filter($settings->only(TenantSetting::IMAGE_COLUMNS)))];
        });

        if ($replacedImages !== []) {
            Storage::disk(TenantSetting::IMAGE_DISK)->delete(array_values($replacedImages));
        }

        // A fresh read, so a row created just now isn't reported as "just created" (a 201 instead of a 200).
        return $settings->fresh();
    }
}
