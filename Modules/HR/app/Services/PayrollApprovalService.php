<?php

namespace Modules\HR\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\JournalPostingService;
use Modules\Core\Services\AuditLogService;
use Modules\HR\Exceptions\HrRuleException;
use Modules\HR\Models\PayrollRun;

/**
 * Takes a payroll run to the books in two steps.
 *
 * Approving posts what the month cost and what is owed, dated the last day of
 * the period:
 *   Dr 6100 Salaries Expense                       pay earned after attendance deductions
 *   Cr 2210 Payroll Taxes & Insurance Payable      insurance + tax withheld
 *   Cr 2200 Salaries Payable                       net pay owed to the employees
 *
 * Paying clears the net pay:
 *   Dr 2200 Salaries Payable
 *   Cr 1110 Cash  or  1120 Bank
 *
 * (Paying the withheld insurance and tax over to the authorities is a manual
 * journal entry against 2210.) Each step locks the run, so it can't be done twice.
 */
class PayrollApprovalService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function approve(PayrollRun $run, array $actor = []): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new HrRuleException('Only a draft payroll run can be approved.');
            }

            $earned = (float) $locked->total_earned;
            $withheld = (float) $locked->total_withheld;
            $net = (float) $locked->total_net;

            $entry = $earned <= 0 ? null : $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Payroll {$locked->period}",
                array_values(array_filter([
                    ['account_code' => '6100', 'debit' => $earned],
                    ['account_code' => '2210', 'credit' => $withheld],
                    ['account_code' => '2200', 'credit' => $net],
                ], fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0)),
                $actor['actor_id'] ?? null,
                $locked->period_end,
            );

            $locked->update([
                'status' => 'approved',
                'journal_entry_id' => $entry?->id,
                'approved_by' => $actor['actor_id'] ?? null,
                'approved_at' => now(),
            ]);

            $this->record($locked, 'APPROVE', ['draft'], ['status' => 'approved', 'journal_entry_id' => $entry?->id], $actor);

            return $locked;
        });
    }

    /**
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    public function pay(PayrollRun $run, string $method, ?string $paymentDate = null, array $actor = []): PayrollRun
    {
        return DB::transaction(function () use ($run, $method, $paymentDate, $actor) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                throw new HrRuleException('This payroll run has already been paid.');
            }

            if ($locked->status !== 'approved') {
                throw new HrRuleException('Approve the payroll run before paying it.');
            }

            $net = (float) $locked->total_net;
            $date = Carbon::parse($paymentDate ?? now()->toDateString());

            $entry = $net <= 0 ? null : $this->journalPosting->post(
                $locked->tenant_id,
                $locked->id,
                "Payroll {$locked->period} paid",
                [
                    ['account_code' => '2200', 'debit' => $net],
                    ['account_code' => PayrollRun::PAYMENT_ACCOUNT_CODES[$method], 'credit' => $net],
                ],
                $actor['actor_id'] ?? null,
                $date,
            );

            $locked->update([
                'status' => 'paid',
                'payment_method' => $method,
                'paid_at' => $date,
                'payment_journal_entry_id' => $entry?->id,
            ]);

            $this->record($locked, 'UPDATE', ['approved'], ['status' => 'paid', 'payment_method' => $method], $actor);

            return $locked;
        });
    }

    /**
     * @param  array<int|string, mixed>  $previous
     * @param  array<string, mixed>  $new
     * @param  array{actor_id?: ?string, actor_name?: ?string, ip_address?: ?string}  $actor
     */
    private function record(PayrollRun $run, string $operation, array $previous, array $new, array $actor): void
    {
        $this->auditLog->record([
            'module' => 'hr',
            'entity_type' => 'PayrollRun',
            'entity_id' => $run->id,
            'operation' => $operation,
            'user_id' => $actor['actor_id'] ?? null,
            'user_name' => $actor['actor_name'] ?? null,
            'tenant_id' => $run->tenant_id,
            'prev_values' => ['status' => $previous[0] ?? null],
            'new_values' => array_map(fn ($v) => $v === null ? null : (string) $v, $new),
            'ip_address' => $actor['ip_address'] ?? null,
        ]);
    }
}
