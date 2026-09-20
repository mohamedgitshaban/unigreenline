<?php

namespace Modules\Core\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\AuditLog;

/**
 * Appends tamper-evident, hash-chained rows to the audit log (spec §5.7).
 *
 * Each entry's hash covers the previous entry's hash, so altering any row's
 * stored data after the fact breaks the chain from that point forward.
 * Callers that need the audit row committed atomically with a business
 * write should call record() from inside their own DB::transaction() —
 * this service joins that transaction rather than opening its own.
 */
class AuditLogService
{
    public const GENESIS = 'GENESIS';

    private const LOCK_NAME = 'audit-log:chain';

    public function __construct(private readonly string $hmacKey) {}

    /**
     * @param  array{
     *     module: string,
     *     entity_type: string,
     *     entity_id?: string|null,
     *     operation: string,
     *     user_id?: string|null,
     *     user_name?: string|null,
     *     tenant_id?: string|null,
     *     warehouse_id?: string|null,
     *     prev_values?: array|null,
     *     new_values?: array|null,
     *     ip_address?: string|null,
     *     request_id?: string|null,
     *     occurred_at?: Carbon|null,
     * }  $attributes
     */
    public function record(array $attributes): AuditLog
    {
        return Cache::lock(self::LOCK_NAME, 10)->block(5, function () use ($attributes) {
            return DB::transaction(function () use ($attributes) {
                $last = DB::table('audit_log')
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->first(['id', 'entry_hash']);

                $nextId = ($last->id ?? 0) + 1;
                $prevHash = $last->entry_hash ?? self::GENESIS;

                // Truncated to whole seconds because the occurred_at column
                // has no fractional-second precision — the hash must be
                // computed from the same value that ends up persisted,
                // or re-verification will recompute a different hash.
                $occurredAt = ($attributes['occurred_at'] ?? now())->clone()->startOfSecond();

                $entryHash = $this->computeHash($prevHash, [
                    'id' => $nextId,
                    'module' => $attributes['module'],
                    'entity_type' => $attributes['entity_type'],
                    'entity_id' => $attributes['entity_id'] ?? null,
                    'operation' => $attributes['operation'],
                    'new_values' => $attributes['new_values'] ?? null,
                    'occurred_at' => $occurredAt,
                ]);

                DB::table('audit_log')->insert([
                    'id' => $nextId,
                    'occurred_at' => $occurredAt,
                    'user_id' => $attributes['user_id'] ?? null,
                    'user_name' => $attributes['user_name'] ?? null,
                    'tenant_id' => $attributes['tenant_id'] ?? null,
                    'warehouse_id' => $attributes['warehouse_id'] ?? null,
                    'module' => $attributes['module'],
                    'entity_type' => $attributes['entity_type'],
                    'entity_id' => $attributes['entity_id'] ?? null,
                    'operation' => $attributes['operation'],
                    'prev_values' => isset($attributes['prev_values']) ? json_encode($attributes['prev_values']) : null,
                    'new_values' => isset($attributes['new_values']) ? json_encode($attributes['new_values']) : null,
                    'ip_address' => $attributes['ip_address'] ?? null,
                    'request_id' => $attributes['request_id'] ?? null,
                    'prev_hash' => $prevHash,
                    'entry_hash' => $entryHash,
                ]);

                return AuditLog::findOrFail($nextId);
            });
        });
    }

    /**
     * Re-walks the chain in insertion order and recomputes every hash,
     * confirming each row's entry_hash matches what the next row recorded
     * as its prev_hash. Returns the id of the first broken row, or null.
     */
    public function verifyChain(): ?int
    {
        $prevHash = self::GENESIS;

        foreach (DB::table('audit_log')->orderBy('id')->cursor() as $row) {
            $expectedHash = $this->computeHash($prevHash, [
                'id' => $row->id,
                'module' => $row->module,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'operation' => $row->operation,
                'new_values' => $row->new_values ? json_decode($row->new_values, true) : null,
                'occurred_at' => Carbon::parse($row->occurred_at),
            ]);

            if ($row->prev_hash !== $prevHash || $row->entry_hash !== $expectedHash) {
                return $row->id;
            }

            $prevHash = $row->entry_hash;
        }

        return null;
    }

    /**
     * @param  array{id: int, module: string, entity_type: string, entity_id: ?string, operation: string, new_values: ?array, occurred_at: Carbon}  $fields
     */
    private function computeHash(string $prevHash, array $fields): string
    {
        $payload = [
            'entity_id' => $fields['entity_id'],
            'entity_type' => $fields['entity_type'],
            'id' => $fields['id'],
            'module' => $fields['module'],
            'new_values' => $fields['new_values'],
            'occurred_at' => $fields['occurred_at']->format('Y-m-d H:i:s'),
            'operation' => $fields['operation'],
        ];

        $canonicalJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $prevHash.$canonicalJson, $this->hmacKey);
    }
}
