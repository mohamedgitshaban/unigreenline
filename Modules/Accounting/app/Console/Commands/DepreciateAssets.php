<?php

namespace Modules\Accounting\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Accounting\Services\FixedAssetService;
use Modules\Core\Models\Tenant;

/**
 * Books the depreciation that has fallen due, for every company (or one with --tenant). Safe to run as often as you like:
 * a line is only ever booked once. Assets set to be posted by hand are left alone unless --all is given.
 */
class DepreciateAssets extends Command
{
    protected $signature = 'assets:depreciate {--date= : Book everything due on or before this date (default today)} {--tenant= : Only this company} {--all : Include assets set to be posted by hand}';

    protected $description = 'Book the fixed-asset depreciation that is due';

    public function handle(FixedAssetService $assets): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?: now());
        $exit = self::SUCCESS;

        Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->each(function (Tenant $tenant) use ($assets, $date, &$exit) {
            $result = $assets->depreciateDue($tenant->id, $date, null, ! $this->option('all'));

            $this->line("{$tenant->name}: {$result['lines']} depreciation line(s) booked on {$result['assets']} asset(s), total {$result['total']}.");

            foreach ($result['failed'] as $failure) {
                $this->error("  {$failure['asset']}: {$failure['message']}");
                $exit = self::FAILURE;
            }
        });

        return $exit;
    }
}
