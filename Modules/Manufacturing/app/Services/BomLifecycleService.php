<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomRevision;
use Modules\Manufacturing\Support\BomStructure;

/**
 * Approving, archiving and restoring recipes, and cutting a new revision on demand.
 */
class BomLifecycleService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly BomRevisionService $revisions,
        private readonly BomStructure $structure,
    ) {}

    /**
     * Draft → approved: from now on orders can be raised from it. The version
     * being approved is snapshotted.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function approve(Bom $bom, array $actor = []): Bom
    {
        return DB::transaction(function () use ($bom, $actor) {
            $locked = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                throw new ManufacturingRuleException('This BoM is already approved.');
            }

            if (! $locked->lines()->exists()) {
                throw new ManufacturingRuleException('A BoM without components cannot be approved.');
            }

            $this->structure->assertNoCycle($locked->tenant_id, $locked->product_id, $locked->lines()->pluck('component_product_id')->all(), $locked->id);

            $locked->update(['status' => 'approved', 'approved_by' => $actor['actor_id'] ?? null, 'approved_at' => now()]);
            $this->revisions->snapshot($locked->refresh(), 'Approved', $actor['actor_id'] ?? null);

            $this->record($locked, ['status' => 'draft'], ['status' => 'approved', 'version' => (string) $locked->version], $actor);

            return $locked;
        });
    }

    /**
     * Retires the recipe. Orders already raised from it are untouched; no new ones can be.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function archive(Bom $bom, array $actor = []): Bom
    {
        return $this->setActive($bom, false, $actor);
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function restore(Bom $bom, array $actor = []): Bom
    {
        return $this->setActive($bom, true, $actor);
    }

    /**
     * Starts a new version of an approved recipe with its current content and a reason,
     * for when a revision date or a formal change needs to be on record.
     *
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function revise(Bom $bom, ?string $note, array $actor = []): BomRevision
    {
        return DB::transaction(function () use ($bom, $note, $actor) {
            $locked = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new ManufacturingRuleException('Only an approved BoM can be revised; approve it first.');
            }

            $locked->increment('version');
            $revision = $this->revisions->snapshot($locked->refresh(), $note, $actor['actor_id'] ?? null);

            $this->record($locked, ['version' => (string) ($locked->version - 1)], ['version' => (string) $locked->version, 'note' => (string) $note], $actor);

            return $revision;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function setActive(Bom $bom, bool $active, array $actor): Bom
    {
        return DB::transaction(function () use ($bom, $active, $actor) {
            $locked = Bom::query()->whereKey($bom->id)->lockForUpdate()->firstOrFail();

            if ($locked->active === $active) {
                throw new ManufacturingRuleException($active ? 'This BoM is not archived.' : 'This BoM is already archived.');
            }

            $locked->update(['active' => $active, 'archived_at' => $active ? null : now()]);

            $this->record($locked, ['active' => $active ? '0' : '1'], ['active' => $active ? '1' : '0'], $actor);

            return $locked;
        });
    }

    /**
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(Bom $bom, array $previous, array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'manufacturing',
            'entity_type' => 'Bom',
            'entity_id' => $bom->id,
            'operation' => 'UPDATE',
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $bom->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
