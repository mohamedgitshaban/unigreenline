<?php

namespace Modules\Manufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\Routing;
use Modules\Manufacturing\Models\RoutingOperation;

/**
 * Routings and their operations. An operation can only depend on one that comes before it in the
 * sequence, which keeps the chain free of loops. Changing a routing makes a new version of every
 * approved recipe that uses it, so orders confirmed afterwards are planned on the new operations
 * while orders already confirmed keep the ones they were confirmed with.
 */
class RoutingService
{
    public function __construct(private readonly BomRevisionService $revisions) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Routing
    {
        $operations = $data['operations'] ?? [];
        unset($data['operations']);

        return DB::transaction(function () use ($data, $operations) {
            $routing = Routing::create($data);

            // Created in sequence order so that "depends on" can point at an earlier one by its sequence.
            foreach (collect($operations)->sortBy('sequence') as $operation) {
                $this->addOperation($routing, $operation, bySequence: true);
            }

            return $routing->load('operations.workCenter');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addOperation(Routing $routing, array $data, bool $bySequence = false): RoutingOperation
    {
        return DB::transaction(function () use ($routing, $data, $bySequence) {
            if ($routing->operations()->where('sequence', $data['sequence'])->exists()) {
                throw ValidationException::withMessages(['sequence' => "This routing already has an operation at sequence {$data['sequence']}."]);
            }

            $data['depends_on_id'] = $this->resolveDependency($routing, $data, $bySequence);
            $operation = $routing->operations()->create($data);
            $this->versionBoms($routing);

            return $operation->load('workCenter');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOperation(RoutingOperation $operation, array $data): RoutingOperation
    {
        return DB::transaction(function () use ($operation, $data) {
            $routing = $operation->routing;

            if (isset($data['sequence']) && $routing->operations()->where('sequence', $data['sequence'])->whereKeyNot($operation->id)->exists()) {
                throw ValidationException::withMessages(['sequence' => "This routing already has an operation at sequence {$data['sequence']}."]);
            }

            if (array_key_exists('depends_on_id', $data)) {
                $data['depends_on_id'] = $this->resolveDependency($routing, $data + ['sequence' => $operation->sequence], false, $operation->id);
            }

            $operation->update($data);
            $this->versionBoms($routing);

            return $operation->refresh()->load('workCenter');
        });
    }

    public function deleteOperation(RoutingOperation $operation): void
    {
        DB::transaction(function () use ($operation) {
            $routing = $operation->routing;
            $operation->delete();
            $this->versionBoms($routing);
        });
    }

    /**
     * The id of the operation this one waits for, validated: same routing, earlier sequence.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDependency(Routing $routing, array $data, bool $bySequence, ?string $selfId = null): ?string
    {
        $reference = $data['depends_on_id'] ?? null;

        if ($reference === null) {
            return null;
        }

        $target = $bySequence
            ? $routing->operations()->where('sequence', $reference)->first()
            : $routing->operations()->whereKey($reference)->first();

        if ($target === null || $target->id === $selfId) {
            throw ValidationException::withMessages(['depends_on_id' => 'An operation can only wait for another operation of the same routing.']);
        }

        if ($target->sequence >= $data['sequence']) {
            throw ValidationException::withMessages(['depends_on_id' => 'An operation can only wait for one that comes earlier in the sequence.']);
        }

        return $target->id;
    }

    /**
     * New version of every approved recipe that runs on this routing.
     */
    private function versionBoms(Routing $routing): void
    {
        Bom::query()->where('routing_id', $routing->id)->where('status', 'approved')->lockForUpdate()->get()->each(function (Bom $bom) {
            $bom->increment('version');
            $this->revisions->snapshot($bom->refresh(), 'Routing changed');
        });
    }
}
