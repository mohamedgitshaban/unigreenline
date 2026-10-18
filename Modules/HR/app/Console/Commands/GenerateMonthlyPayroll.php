<?php

namespace Modules\HR\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Tenant;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\PayrollRun;
use Modules\HR\Services\GeneratePayrollService;

/**
 * Calculates the month's payroll for every company as a draft. Runs by itself on
 * the last day of each month (see HRServiceProvider); `--period=2026-09` does
 * a past month. A company that already has a run for the period is skipped, and
 * one with nobody on the payroll is reported, not failed. Nothing reaches the
 * ledger until someone approves the draft.
 */
class GenerateMonthlyPayroll extends Command
{
    protected $signature = 'hr:generate-payroll {--period= : The month to calculate, e.g. 2026-09 (default: the current month)}';

    protected $description = 'Calculate the monthly payroll as a draft for every tenant';

    public function handle(GeneratePayrollService $payroll): int
    {
        $period = $this->option('period') ?: Carbon::now()->format('Y-m');
        $created = 0;

        foreach (Tenant::query()->get() as $tenant) {
            if (PayrollRun::query()->where('tenant_id', $tenant->id)->where('period', $period)->exists()) {
                continue;
            }

            try {
                $payroll->generate($tenant->id, $period);
                $created++;
            } catch (HrRuleException $e) {
                $this->warn("{$tenant->name}: {$e->getMessage()}");
            }
        }

        $this->info("Created {$created} draft payroll run(s) for {$period}.");

        return self::SUCCESS;
    }
}
