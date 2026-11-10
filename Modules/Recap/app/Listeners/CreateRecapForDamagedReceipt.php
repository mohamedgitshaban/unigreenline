<?php

namespace Modules\Recap\Listeners;

use Modules\Inventory\Events\DamagedGoodsReceived;
use Modules\Recap\Services\CreateRecapService;

/**
 * Goods that arrived damaged, or that must be destroyed, are written off through the usual Recap document:
 * a draft per kind, waiting for the warehouse manager to approve it. Nothing is posted until then.
 */
class CreateRecapForDamagedReceipt
{
    public function __construct(private readonly CreateRecapService $recaps) {}

    public function handle(DamagedGoodsReceived $event): void
    {
        $kinds = [
            ['cartons' => $event->damagedCartons, 'reason' => 'damaged', 'notes' => 'Damaged on receipt'],
            ['cartons' => $event->destroyedCartons, 'reason' => 'other', 'notes' => 'To be destroyed, found on receipt'],
        ];

        foreach (array_filter($kinds, fn (array $kind) => $kind['cartons'] > 0) as $kind) {
            $recap = $this->recaps->create([
                'tenant_id' => $event->batch->tenant_id,
                'warehouse_id' => $event->batch->warehouse_id,
                'reason' => $kind['reason'],
                'notes' => "{$kind['notes']} (batch {$event->batch->batch_no})",
                'created_by' => $event->actor['actor_id'] ?? null,
                'lines' => [['batch_id' => $event->batch->id, 'qty_cartons' => $kind['cartons']]],
            ], $event->actor);

            $event->draftedWriteOff($recap->id);
        }
    }
}
