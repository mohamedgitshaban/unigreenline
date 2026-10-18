<?php

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\Employee;
use Modules\HR\Models\TimePermission;

/**
 * "Permissions" in the HR sense — an approved slip that excuses part of a
 * working day: arriving late, leaving early, or being away for a stretch in the
 * middle (partial attendance). The minutes it covers are not deducted from pay
 * (see PayrollCalculator); an unapproved or rejected one covers nothing.
 */
class TimePermissionService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{employee_id: string, type: string, permission_date: string, from_time: string, to_time: string, reason?: ?string, created_by?: ?string}  $data
     */
    public function create(string $tenantId, array $data): TimePermission
    {
        Employee::query()->where('tenant_id', $tenantId)->findOrFail($data['employee_id']);

        return DB::transaction(function () use ($tenantId, $data) {
            $permission = TimePermission::create($data + ['tenant_id' => $tenantId, 'minutes' => $this->minutesBetween($data['from_time'], $data['to_time'])]);

            $this->record($permission, 'INSERT', ['type' => $permission->type, 'minutes' => (string) $permission->minutes], $data['created_by'] ?? null);

            return $permission;
        });
    }

    /**
     * @param  array{type?: string, permission_date?: string, from_time?: string, to_time?: string, reason?: ?string}  $data
     */
    public function update(TimePermission $permission, array $data, User $actor): TimePermission
    {
        $this->assertPending($permission);

        $from = $data['from_time'] ?? $permission->from_time;
        $to = $data['to_time'] ?? $permission->to_time;

        if ($this->minutesBetween($from, $to) <= 0) {
            throw new HrRuleException('The end of the permission must be after its start.');
        }

        $permission->update($data + ['minutes' => $this->minutesBetween($from, $to)]);
        $this->record($permission, 'UPDATE', array_map('strval', $data), $actor->id);

        return $permission;
    }

    public function decide(TimePermission $permission, string $decision, User $actor, ?string $reason = null): TimePermission
    {
        return DB::transaction(function () use ($permission, $decision, $actor, $reason) {
            $locked = TimePermission::query()->whereKey($permission->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->update([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $decision === 'rejected' ? $reason : null,
            ]);

            $this->record($locked, $decision === 'approved' ? 'APPROVE' : 'UPDATE', ['status' => $decision], $actor->id);

            return $locked;
        });
    }

    public function minutesBetween(string $from, string $to): int
    {
        $minutes = fn (string $time) => (int) explode(':', $time)[0] * 60 + (int) explode(':', $time)[1];

        return $minutes($to) - $minutes($from);
    }

    private function assertPending(TimePermission $permission): void
    {
        if (! $permission->isPending()) {
            throw new HrRuleException('Only a pending permission can be changed.');
        }
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function record(TimePermission $permission, string $operation, array $new, ?string $userId): void
    {
        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'TimePermission',
            'entity_id' => $permission->id,
            'operation' => $operation,
            'user_id' => $userId,
            'tenant_id' => $permission->tenant_id,
            'new_values' => $new,
        ]);
    }
}
