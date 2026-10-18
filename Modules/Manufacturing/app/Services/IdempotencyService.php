<?php

namespace Modules\Manufacturing\Services;

use Closure;
use Modules\Manufacturing\Models\ManufacturingOperation;
use Modules\Manufacturing\Models\ProductionOrder;

/**
 * Makes a request that carries an idempotency key safe to repeat: the first time
 * the work runs and its outcome is stored; any retry with the same key gets that
 * outcome back and does nothing. Call it with the order row already locked, so two
 * simultaneous retries are handled one after the other.
 */
class IdempotencyService
{
    /**
     * @param  Closure(): array<string, mixed>  $work
     * @return array{replayed: bool, result: array<string, mixed>}
     */
    public function once(ProductionOrder $lockedOrder, string $action, ?string $key, Closure $work): array
    {
        if ($key === null || $key === '') {
            return ['replayed' => false, 'result' => $work()];
        }

        $existing = ManufacturingOperation::query()
            ->where('production_order_id', $lockedOrder->id)->where('action', $action)->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return ['replayed' => true, 'result' => $existing->result ?? []];
        }

        $result = $work();

        ManufacturingOperation::create([
            'tenant_id' => $lockedOrder->tenant_id, 'production_order_id' => $lockedOrder->id,
            'action' => $action, 'idempotency_key' => $key, 'result' => $result,
        ]);

        return ['replayed' => false, 'result' => $result];
    }
}
