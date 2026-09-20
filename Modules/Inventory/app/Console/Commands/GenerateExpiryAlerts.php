<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Notification;
use Modules\Inventory\Models\InventoryBatch;

/**
 * Creates a one-time notification for each batch newly within the
 * "critical expiry" window (spec §10.8's "expiry alerts" / "notification
 * generation"). Idempotent per batch — checked via link_module/link_id, so
 * running this daily never re-notifies for the same batch. Meant to run
 * daily.
 *
 * The 30-day threshold matches Analytics::DashboardService's
 * CRITICAL_EXPIRY_DAYS (kept as a separate constant since Analytics
 * depends on Inventory, not the other way around — duplicated, not shared).
 */
class GenerateExpiryAlerts extends Command
{
    public const CRITICAL_EXPIRY_DAYS = 30;

    protected $signature = 'inventory:generate-expiry-alerts';

    protected $description = 'Create a notification for each batch newly within the critical expiry window';

    public function handle(): int
    {
        $alreadyNotifiedBatchIds = Notification::query()
            ->where('type', 'expiry_alert')
            ->where('link_module', 'inventory')
            ->pluck('link_id');

        $batches = InventoryBatch::query()
            ->where('qty_cartons', '>', 0)
            ->whereDate('exp_date', '<=', now()->addDays(self::CRITICAL_EXPIRY_DAYS))
            ->whereNotIn('id', $alreadyNotifiedBatchIds)
            ->with(['product', 'warehouse'])
            ->get();

        foreach ($batches as $batch) {
            Notification::create([
                'tenant_id' => $batch->tenant_id,
                'user_id' => null,
                'type' => 'expiry_alert',
                'title' => 'Batch nearing expiry',
                'body' => "Batch {$batch->batch_no} of {$batch->product?->name} in {$batch->warehouse?->name} expires {$batch->exp_date->toDateString()}.",
                'link_module' => 'inventory',
                'link_id' => $batch->id,
                'unread' => true,
            ]);
        }

        $this->info("Created {$batches->count()} expiry alert(s).");

        return self::SUCCESS;
    }
}
