<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AuditLogService;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\UnitConverter;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Models\RoutingOperation;
use Modules\Manufacturing\Support\BomStructure;

/**
 * Creates or edits a bill of materials. `lines` and `by_products`, when given
 * on an edit, replace the existing ones.
 *
 * Revisions: a BoM that is in use (approved) gets a new version number whenever
 * its content changes, and a snapshot of every approved version is kept. Orders
 * keep their own copy of the components they were confirmed with, so editing a
 * BoM never moves an order already under way. If the company requires approval
 * of recipes, an edit sends the BoM back to draft until it is approved again.
 */
class SaveBomService
{
    /** Changes to these make a new version of an approved BoM. */
    private const STRUCTURAL = ['product_id', 'output_qty_cartons', 'conversion_cost', 'routing_id'];

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly NumberSequenceService $numbers,
        private readonly BomRevisionService $revisions,
        private readonly BomStructure $structure,
        private readonly UnitConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function create(array $data, array $actor = []): Bom
    {
        $lines = $this->normalizeLines($data['tenant_id'], $data['lines']);
        $byProducts = $data['by_products'] ?? [];
        unset($data['lines'], $data['by_products']);

        $this->assertValid($data['tenant_id'], $data['product_id'], $data['routing_id'] ?? null, $lines, $byProducts, null);

        return DB::transaction(function () use ($data, $lines, $byProducts, $actor) {
            $settings = ManufacturingSetting::forTenant($data['tenant_id']);
            $approved = ! $settings->require_bom_approval;

            $bom = Bom::create($data + [
                'code' => $this->numbers->next($data['tenant_id'], 'bom', $settings->bom_prefix, $settings->number_padding),
                'version' => 1,
                'status' => $approved ? 'approved' : 'draft',
                'approved_by' => $approved ? ($actor['actor_id'] ?? null) : null,
                'approved_at' => $approved ? now() : null,
            ]);
            $bom->lines()->createMany($lines);
            $bom->byProducts()->createMany($byProducts);

            if ($approved) {
                $this->revisions->snapshot($bom->refresh(), 'Created', $actor['actor_id'] ?? null);
            }

            $this->record($bom, 'INSERT', null, ['code' => $bom->code, 'name' => $bom->name, 'product_id' => $bom->product_id, 'lines' => count($lines), 'status' => $bom->status], $actor);

            return $bom->load(['lines', 'byProducts']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function update(Bom $bom, array $data, array $actor = []): Bom
    {
        $lines = isset($data['lines']) ? $this->normalizeLines($bom->tenant_id, $data['lines']) : null;
        $byProducts = $data['by_products'] ?? null;
        unset($data['lines'], $data['by_products']);

        $this->assertValid(
            $bom->tenant_id,
            $data['product_id'] ?? $bom->product_id,
            array_key_exists('routing_id', $data) ? $data['routing_id'] : $bom->routing_id,
            $lines ?? $bom->lines()->get()->map(fn ($l) => ['component_product_id' => $l->component_product_id, 'operation_id' => $l->operation_id])->all(),
            $byProducts ?? $bom->byProducts()->get()->map(fn ($b) => ['product_id' => $b->product_id])->all(),
            $bom->id,
        );

        return DB::transaction(function () use ($bom, $data, $lines, $byProducts, $actor) {
            $bom = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();
            $settings = ManufacturingSetting::forTenant($bom->tenant_id);

            $bom->fill($data);

            if ($bom->isDirty('active')) {
                $bom->archived_at = $bom->active ? null : now();
            }

            $dirty = $bom->getDirty();
            $previous = array_intersect_key($bom->getOriginal(), $dirty);
            $structural = $lines !== null || $byProducts !== null || array_intersect(self::STRUCTURAL, array_keys($dirty)) !== [];

            if ($structural && $bom->status === 'approved') {
                $bom->version++;

                if ($settings->require_bom_approval) {
                    $bom->status = 'draft';
                    $bom->approved_by = null;
                    $bom->approved_at = null;
                }
            }

            $bom->save();

            if ($lines !== null) {
                $bom->lines()->delete();
                $bom->lines()->createMany($lines);
            }

            if ($byProducts !== null) {
                $bom->byProducts()->delete();
                $bom->byProducts()->createMany($byProducts);
            }

            if ($structural && $bom->status === 'approved') {
                $this->revisions->snapshot($bom->refresh(), 'Edited', $actor['actor_id'] ?? null);
            }

            $this->record(
                $bom, 'UPDATE',
                array_map('strval', $previous),
                array_map('strval', $dirty) + ($lines !== null ? ['lines' => count($lines).' replaced'] : []) + ($structural ? ['version' => (string) $bom->version] : []),
                $actor,
            );

            return $bom->load(['lines', 'byProducts']);
        });
    }

    /**
     * Turns lines entered in a packaging unit into whole cartons, as purchase orders do.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(string $tenantId, array $lines): array
    {
        return array_map(function (array $line, int $index) use ($tenantId) {
            if (! empty($line['unit_id'])) {
                $product = Product::query()->with('units')->where('tenant_id', $tenantId)->findOrFail($line['component_product_id']);
                $line['qty_cartons'] = $this->units->toCartons($product, (int) ($line['unit_qty'] ?? 0), $line['unit_id'], "lines.{$index}.unit_qty");
            } else {
                $line['unit_id'] = null;
                $line['unit_qty'] = null;
            }

            return $line;
        }, $lines, array_keys($lines));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $byProducts
     */
    private function assertValid(string $tenantId, string $productId, ?string $routingId, array $lines, array $byProducts, ?string $ignoreBomId): void
    {
        $componentIds = array_column($lines, 'component_product_id');

        $this->structure->assertNoCycle($tenantId, $productId, $componentIds, $ignoreBomId);

        foreach ($byProducts as $index => $byProduct) {
            if ($byProduct['product_id'] === $productId) {
                throw ValidationException::withMessages(["by_products.{$index}.product_id" => 'The finished product cannot also be its own by-product.']);
            }

            if (in_array($byProduct['product_id'], $componentIds, true)) {
                throw ValidationException::withMessages(["by_products.{$index}.product_id" => 'A by-product cannot also be a component of the same recipe.']);
            }
        }

        if (array_sum(array_column($byProducts, 'cost_share_pct')) > 100) {
            throw ValidationException::withMessages(['by_products' => 'The cost shares of the by-products cannot add up to more than 100%.']);
        }

        foreach ($lines as $index => $line) {
            $operationId = $line['operation_id'] ?? null;

            if ($operationId === null) {
                continue;
            }

            $belongs = $routingId !== null
                && RoutingOperation::query()->whereKey($operationId)->where('routing_id', $routingId)->exists();

            if (! $belongs) {
                throw ValidationException::withMessages(["lines.{$index}.operation_id" => 'That operation is not part of this recipe\'s routing.']);
            }
        }
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
