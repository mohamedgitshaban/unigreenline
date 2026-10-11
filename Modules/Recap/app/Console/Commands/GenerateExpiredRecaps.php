<?php

namespace Modules\Recap\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Tenant;
use Modules\Recap\Services\ExpiredRecapGenerator;

/**
 * Drafts write-off recaps for expired stock, per tenant. Idempotent —
 * batches already on a draft recap are skipped. Drafts only: nothing is
 * deducted or posted until someone approves them. Meant to run daily.
 */
class GenerateExpiredRecaps extends Command
{
    protected $signature = 'recap:generate-expired';

    protected $description = 'Create draft write-off recaps for batches that have passed their expiry date';

    public function handle(ExpiredRecapGenerator $generator): int
    {
        $created = Tenant::query()->get()
            ->sum(fn (Tenant $tenant) => $generator->generate($tenant->id)->count());

        $this->info("Created {$created} draft recap(s).");

        return self::SUCCESS;
    }
}
