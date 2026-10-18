<?php

namespace Modules\Manufacturing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\Tenant;
use Modules\Manufacturing\Exceptions\ManufacturingRuleException;
use Modules\Manufacturing\Models\ManufacturingSetting;
use Modules\Manufacturing\Services\MrpRunService;

/**
 * Plans the default warehouse of every company (or one, with --tenant). Only proposes: it never creates
 * an order. A company with no default warehouse set, or with a run already waiting, is skipped.
 */
class RunMrpPlanning extends Command
{
    protected $signature = 'mrp:plan {--tenant= : Only this company (id)} {--sync : Run now instead of queueing}';

    protected $description = 'Run material requirements planning for the default warehouse of every company';

    public function handle(MrpRunService $runs): int
    {
        $count = 0;

        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($tenants as $tenant) {
            if (ManufacturingSetting::forTenant($tenant->id)->default_warehouse_id === null) {
                $this->warn("{$tenant->name}: no default warehouse set, skipped.");

                continue;
            }

            try {
                $run = $runs->create($tenant->id, [], null);
            } catch (ManufacturingRuleException $e) {
                $this->warn("{$tenant->name}: {$e->getMessage()}");

                continue;
            }

            $this->option('sync') ? $runs->execute($run->id) : $runs->dispatch($run);
            $count++;
        }

        $this->info("Planning started for {$count} company(ies).");

        return self::SUCCESS;
    }
}
