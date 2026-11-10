<?php

namespace Modules\Inventory\Events;

use Modules\Inventory\Models\InventoryBatch;

/**
 * Fired when goods are received and some of them arrived damaged or have to be destroyed. They went into stock
 * with the rest; the Recap module listens and drafts the write-off for the manager to approve, recording the ids
 * of the drafts with {@see draftedWriteOff()}.
 */
class DamagedGoodsReceived
{
    /** @var array<int, string> */
    public array $recapIds = [];

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function __construct(
        public readonly InventoryBatch $batch,
        public readonly int $damagedCartons,
        public readonly int $destroyedCartons,
        public readonly array $actor,
    ) {}

    public function draftedWriteOff(string $recapId): void
    {
        $this->recapIds[] = $recapId;
    }
}
