<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Manufacturing\Models\Bom;

/**
 * Creates or edits a bill of materials. `lines`, when given on an edit,
 * replace every existing line. Editing a BOM never touches production orders
 * already raised from it: completed orders keep their own consumption records.
 */
class SaveBomService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{tenant_id: string, product_id: string, name: string, output_qty_cartons?: int, conversion_cost?: float, notes?: ?string, active?: bool, lines: array<int, array{component_product_id: string, qty_cartons: int}>}  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): Bom
    {
        $lines = $data['lines'];
        unset($data['lines']);

        return DB::transaction(function () use ($data, $lines, $actor) {
            $bom = Bom::create($data);
            $bom->lines()->createMany($lines);

            $this->record($bom, 'INSERT', null, ['name' => $bom->name, 'product_id' => $bom->product_id, 'lines' => count($lines)], $actor);

            return $bom->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(Bom $bom, array $data, array $actor = []): Bom
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        return DB::transaction(function () use ($bom, $data, $lines, $actor) {
            $bom->fill($data);
            $dirty = $bom->getDirty();
            $previous = array_intersect_key($bom->getOriginal(), $dirty);
            $bom->save();

            if ($lines !== null) {
                $bom->lines()->delete();
                $bom->lines()->createMany($lines);
            }

            $this->record(
                $bom, 'UPDATE',
                array_map('strval', $previous),
                array_map('strval', $dirty) + ($lines !== null ? ['lines' => count($lines).' replaced'] : []),
                $actor,
            );

            return $bom->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(Bom $bom, string $operation, ?array $previous, ?array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'manufacturing',
            'entity_type' => 'Bom',
            'entity_id' => $bom->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $bom->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
