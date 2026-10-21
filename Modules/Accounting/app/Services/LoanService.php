<?php

namespace Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Accounting\Exceptions\AccountingRuleException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Loan;
use Modules\Accounting\Models\LoanInstallment;
use Modules\Accounting\Support\LoanScheduleCalculator;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;

/**
 * The life of a loan, whether we borrowed the money or lent it: agreeing the terms and the repayment schedule, handing the
 * money over, paying instalments, repaying part or all of it early. Every posting goes through {@see JournalPostingService}.
 *
 * Borrowed:  activate  Dr bank / Cr loans payable;       instalment  Dr loans payable + Dr interest expense / Cr bank.
 * Granted:   activate  Dr loans receivable / Cr bank;    instalment  Dr bank / Cr loans receivable + Cr interest income.
 */
class LoanService
{
    public const DEFAULT_ACCOUNTS = [
        'borrowed' => ['principal' => '2400', 'interest' => '6600'],
        'granted' => ['principal' => '1600', 'interest' => '4300'],
    ];

    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly AuditLogService $auditLog,
        private readonly AccountingNumbers $numbers,
    ) {}

    /**
     * Agrees a loan as a draft and plans its repayments.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Loan
    {
        return DB::transaction(function () use ($data, $actor) {
            $kind = $data['kind'] ?? 'borrowed';
            $months = (int) ($data['period_months'] ?? 1);
            $start = CarbonImmutable::parse($data['start_date']);

            $loan = Loan::create([
                'tenant_id' => $actor->tenant_id,
                'number' => $this->numbers->next($actor->tenant_id, 'loan', 'LN'),
                'name' => $data['name'],
                'kind' => $kind,
                'counterparty_type' => $data['counterparty_type'] ?? null,
                'counterparty_id' => $data['counterparty_id'] ?? null,
                'counterparty_name' => $data['counterparty_name'] ?? null,
                'principal' => round((float) $data['principal'], 2),
                'annual_rate_pct' => $data['annual_rate_pct'] ?? 0,
                'method' => $data['method'] ?? 'annuity',
                'periods' => (int) $data['periods'],
                'period_months' => $months,
                'grace_periods' => (int) ($data['grace_periods'] ?? 0),
                'start_date' => $start,
                'first_due_date' => $data['first_due_date'] ?? $start->addMonthsNoOverflow($months),
                'disbursement_account_code' => $data['disbursement_account_code'] ?? null,
                'principal_account_code' => $data['principal_account_code'] ?? self::DEFAULT_ACCOUNTS[$kind]['principal'],
                'interest_account_code' => $data['interest_account_code'] ?? self::DEFAULT_ACCOUNTS[$kind]['interest'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->planFromScratch($loan);
            $this->audit($actor, $loan, 'INSERT', null, ['number' => $loan->number, 'kind' => $kind, 'principal' => (string) $loan->principal]);

            return $loan->refresh();
        });
    }

    /**
     * Only a draft can change; its schedule is redrawn.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Loan $loan, array $data, User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            $locked = $this->lock($loan);
            $descriptive = ['name', 'counterparty_type', 'counterparty_id', 'counterparty_name', 'notes'];
            $terms = ['kind', 'principal', 'annual_rate_pct', 'method', 'periods', 'period_months', 'grace_periods', 'start_date', 'first_due_date', 'disbursement_account_code', 'principal_account_code', 'interest_account_code'];

            if ($locked->status !== 'draft' && array_intersect_key($data, array_flip($terms)) !== []) {
                throw new AccountingRuleException('Only a draft loan can have its terms changed.');
            }

            if (in_array($locked->status, ['closed', 'cancelled'], true)) {
                throw new AccountingRuleException('This loan is finished and cannot be changed.');
            }

            $previous = $locked->only(array_keys($data));
            $locked->fill(array_intersect_key($data, array_flip([...$descriptive, ...$terms])));

            if (isset($data['kind']) && $data['kind'] !== $locked->getOriginal('kind')) {
                $locked->principal_account_code = $data['principal_account_code'] ?? self::DEFAULT_ACCOUNTS[$data['kind']]['principal'];
                $locked->interest_account_code = $data['interest_account_code'] ?? self::DEFAULT_ACCOUNTS[$data['kind']]['interest'];
            }

            if ($locked->status === 'draft' && (isset($data['start_date']) || isset($data['period_months'])) && ! isset($data['first_due_date'])) {
                $locked->first_due_date = CarbonImmutable::parse($locked->start_date)->addMonthsNoOverflow($locked->period_months);
            }

            $dirty = $locked->getDirty();
            $locked->save();

            if ($locked->status === 'draft' && array_intersect_key($dirty, array_flip($terms)) !== []) {
                $this->planFromScratch($locked);
            }

            if ($dirty !== []) {
                $this->audit($actor, $locked, 'UPDATE', array_intersect_key($previous, $dirty), $dirty);
            }

            return $locked->refresh();
        });
    }

    /**
     * The repayment schedule for these terms, without saving anything.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function preview(array $data): array
    {
        $months = (int) ($data['period_months'] ?? 1);

        return $this->calculate(
            (float) $data['principal'], (float) ($data['annual_rate_pct'] ?? 0), $data['method'] ?? 'annuity', (int) $data['periods'], $months,
            $data['first_due_date'] ?? CarbonImmutable::parse($data['start_date'])->addMonthsNoOverflow($months), (int) ($data['grace_periods'] ?? 0),
        );
    }

    /**
     * Hands the money over (or receives it): the loan becomes active and owes (or is owed) the principal.
     */
    public function activate(Loan $loan, string $accountCode, User $actor, ?DateTimeInterface $date = null): Loan
    {
        return DB::transaction(function () use ($loan, $accountCode, $actor, $date) {
            $locked = $this->lock($loan);

            if ($locked->status !== 'draft') {
                throw new AccountingRuleException('Only a draft loan can be activated.');
            }

            $this->assertAccountsExist($locked->tenant_id, [$accountCode, $locked->principal_account_code, $locked->interest_account_code]);
            $amount = (float) $locked->principal;

            $entry = $this->journal->post(
                $locked->tenant_id, $locked->id, ($locked->kind === 'borrowed' ? 'Loan received ' : 'Loan granted ')."{$locked->number} {$locked->name}",
                $locked->kind === 'borrowed'
                    ? [['account_code' => $accountCode, 'debit' => $amount], ['account_code' => $locked->principal_account_code, 'credit' => $amount]]
                    : [['account_code' => $locked->principal_account_code, 'debit' => $amount], ['account_code' => $accountCode, 'credit' => $amount]],
                $actor->id, $date ?? $locked->start_date,
            );

            $locked->forceFill([
                'status' => 'active',
                'disbursement_account_code' => $accountCode,
                'outstanding_principal' => $amount,
                'activated_at' => now(),
                'disbursement_journal_entry_id' => $entry->id,
            ])->save();

            $this->audit($actor, $locked, 'APPROVE', ['status' => 'draft'], ['status' => 'active', 'journal_entry_id' => $entry->id]);

            return $locked->refresh();
        });
    }

    /**
     * Pays (or collects) the next instalment. Instalments go in order.
     *
     * @param  array{payment_date?: ?string, payment_account_code?: ?string}  $data
     */
    public function payInstallment(Loan $loan, int $sequence, array $data, User $actor): LoanInstallment
    {
        return DB::transaction(function () use ($loan, $sequence, $data, $actor) {
            $locked = $this->lock($loan);

            if ($locked->status !== 'active') {
                throw new AccountingRuleException('Instalments can only be paid on an active loan.');
            }

            $installment = LoanInstallment::query()->where('loan_id', $locked->id)->where('sequence', $sequence)->lockForUpdate()->first()
                ?? throw new AccountingRuleException("This loan has no instalment {$sequence}.");

            if ($installment->status === 'paid') {
                throw new AccountingRuleException('This instalment has already been paid.');
            }

            if ($locked->installments()->where('status', 'planned')->where('sequence', '<', $sequence)->exists()) {
                throw new AccountingRuleException('Earlier instalments have to be paid first.');
            }

            $code = $data['payment_account_code'] ?? $locked->disbursement_account_code ?? throw new AccountingRuleException('Say which account the instalment is paid from.');
            $this->assertAccountsExist($locked->tenant_id, [$code]);
            $date = CarbonImmutable::parse($data['payment_date'] ?? now())->startOfDay();

            $entry = $this->postRepayment($locked, $code, (float) $installment->principal_amount, (float) $installment->interest_amount, $date, "Instalment {$sequence} of loan {$locked->number}", $actor);

            $installment->forceFill(['status' => 'paid', 'paid_on' => $date, 'payment_account_code' => $code, 'journal_entry_id' => $entry?->id, 'paid_by' => $actor->id])->save();

            $locked->forceFill([
                'outstanding_principal' => round((float) $locked->outstanding_principal - (float) $installment->principal_amount, 2),
                'interest_paid' => round((float) $locked->interest_paid + (float) $installment->interest_amount, 2),
            ]);

            $closed = ! $locked->installments()->where('status', 'planned')->exists();

            if ($closed) {
                $locked->forceFill(['status' => 'closed', 'closed_at' => now()]);
            }

            $locked->save();
            $this->audit($actor, $locked, 'UPDATE', null, ['installment' => $sequence, 'principal' => (string) $installment->principal_amount, 'interest' => (string) $installment->interest_amount, 'outstanding' => (string) $locked->outstanding_principal]);

            return $installment->refresh();
        });
    }

    /**
     * Repays part of the principal (or all of it) before it falls due.
     *
     *  - reduce_term: the instalment stays the same and the loan ends sooner.
     *  - reduce_installment: the loan ends when it was going to and the instalments get smaller.
     *  - payoff: pays the whole outstanding principal plus the interest run up since the last instalment (or the start) and closes the loan.
     *
     * @param  array{mode: string, amount?: float|string, date?: ?string, payment_account_code?: ?string}  $data
     */
    public function repayEarly(Loan $loan, array $data, User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $data, $actor) {
            $locked = $this->lock($loan);

            if ($locked->status !== 'active') {
                throw new AccountingRuleException('Only an active loan can be repaid early.');
            }

            $mode = $data['mode'];
            $date = CarbonImmutable::parse($data['date'] ?? now())->startOfDay();
            $code = $data['payment_account_code'] ?? $locked->disbursement_account_code ?? throw new AccountingRuleException('Say which account the repayment goes through.');
            $this->assertAccountsExist($locked->tenant_id, [$code]);
            $outstanding = round((float) $locked->outstanding_principal, 2);
            $planned = $locked->installments()->where('status', 'planned')->lockForUpdate()->get();

            if ($mode === 'payoff') {
                $since = $locked->installments()->where('status', 'paid')->max('due_date') ?? $locked->start_date;
                $days = max(0, (int) CarbonImmutable::parse($since)->startOfDay()->diffInDays($date, false));
                $interest = round($outstanding * (float) $locked->annual_rate_pct / 100 * $days / 365, 2);

                $this->postRepayment($locked, $code, $outstanding, $interest, $date, "Early payoff of loan {$locked->number}", $actor);
                $locked->installments()->where('status', 'planned')->delete();
                $locked->forceFill(['outstanding_principal' => 0, 'interest_paid' => round((float) $locked->interest_paid + $interest, 2), 'status' => 'closed', 'closed_at' => now()])->save();
                $this->audit($actor, $locked, 'UPDATE', null, ['early' => 'payoff', 'principal' => (string) $outstanding, 'interest' => (string) $interest]);

                return $locked->refresh();
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);

            if ($amount <= 0 || $amount >= $outstanding) {
                throw new AccountingRuleException('The amount must be more than zero and less than what is still owed. To repay everything, use the payoff mode.');
            }

            $first = $planned->first() ?? throw new AccountingRuleException('There are no instalments left to change.');
            $this->postRepayment($locked, $code, $amount, 0.0, $date, "Early repayment on loan {$locked->number}", $actor);

            $newOutstanding = round($outstanding - $amount, 2);
            $rate = LoanScheduleCalculator::periodRate((float) $locked->annual_rate_pct, $locked->period_months);
            $periods = $planned->count();

            if ($mode === 'reduce_term' && $periods > 1) {
                $periods = match ($locked->method) {
                    'annuity' => $this->periodsFor($newOutstanding, $rate, (float) $first->payment_amount, $periods),
                    'fixed_principal' => max(1, (int) ceil($newOutstanding / max((float) $first->principal_amount, 0.01))),
                    default => $periods,
                };
            }

            $locked->installments()->where('status', 'planned')->delete();
            $keep = $mode === 'reduce_term' ? match ($locked->method) {
                'annuity' => (float) $first->payment_amount,
                'fixed_principal' => (float) $first->principal_amount,
                default => null,
            } : null;

            $rows = LoanScheduleCalculator::schedule($newOutstanding, (float) $locked->annual_rate_pct, $locked->method, $periods, $locked->period_months, $first->due_date, 0, (int) $first->sequence, $keep);

            foreach ($rows as $row) {
                $locked->installments()->create($row + ['tenant_id' => $locked->tenant_id, 'status' => 'planned']);
            }

            $locked->forceFill(['outstanding_principal' => $newOutstanding])->save();
            $this->audit($actor, $locked, 'UPDATE', null, ['early' => $mode, 'principal' => (string) $amount, 'outstanding' => (string) $newOutstanding, 'instalments_left' => $periods]);

            return $locked->refresh();
        });
    }

    public function cancel(Loan $loan, User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $actor) {
            $locked = $this->lock($loan);

            if ($locked->status !== 'draft') {
                throw new AccountingRuleException('Only a draft loan can be cancelled.');
            }

            $locked->installments()->delete();
            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->audit($actor, $locked, 'UPDATE', ['status' => 'draft'], ['status' => 'cancelled']);

            return $locked->refresh();
        });
    }

    public function delete(Loan $loan, User $actor): void
    {
        DB::transaction(function () use ($loan, $actor) {
            $locked = $this->lock($loan);

            if (! in_array($locked->status, ['draft', 'cancelled'], true)) {
                throw new AccountingRuleException('A loan that was activated cannot be deleted.');
            }

            $snapshot = ['number' => $locked->number, 'name' => $locked->name];
            $locked->delete();
            $this->audit($actor, $locked, 'DELETE', $snapshot, null);
        });
    }

    private function lock(Loan $loan): Loan
    {
        return Loan::query()->where('tenant_id', $loan->tenant_id)->whereKey($loan->id)->lockForUpdate()->firstOrFail();
    }

    private function planFromScratch(Loan $loan): void
    {
        $loan->installments()->delete();

        $rows = $this->calculate((float) $loan->principal, (float) $loan->annual_rate_pct, $loan->method, $loan->periods, $loan->period_months, $loan->first_due_date, $loan->grace_periods);

        foreach ($rows as $row) {
            $loan->installments()->create($row + ['tenant_id' => $loan->tenant_id, 'status' => 'planned']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function calculate(float $principal, float $rate, string $method, int $periods, int $months, string|DateTimeInterface $firstDue, int $grace): array
    {
        try {
            return LoanScheduleCalculator::schedule($principal, $rate, $method, $periods, $months, $firstDue, $grace);
        } catch (InvalidArgumentException $e) {
            throw new AccountingRuleException($e->getMessage());
        }
    }

    private function periodsFor(float $principal, float $rate, float $payment, int $fallback): int
    {
        try {
            return min($fallback, LoanScheduleCalculator::periodsFor($principal, $rate, $payment));
        } catch (InvalidArgumentException) {
            return $fallback;
        }
    }

    /**
     * Posts what is paid (borrowed) or collected (granted) against the loan: the principal against the loan account and the interest against its own.
     */
    private function postRepayment(Loan $loan, string $accountCode, float $principal, float $interest, CarbonImmutable $date, string $description, User $actor): ?object
    {
        $total = round($principal + $interest, 2);

        if ($total <= 0) {
            return null;
        }

        $lines = $loan->kind === 'borrowed'
            ? [
                ['account_code' => $loan->principal_account_code, 'debit' => $principal],
                ['account_code' => $loan->interest_account_code, 'debit' => $interest],
                ['account_code' => $accountCode, 'credit' => $total],
            ]
            : [
                ['account_code' => $accountCode, 'debit' => $total],
                ['account_code' => $loan->principal_account_code, 'credit' => $principal],
                ['account_code' => $loan->interest_account_code, 'credit' => $interest],
            ];

        return $this->journal->post(
            $loan->tenant_id, $loan->id, $description,
            array_values(array_filter($lines, fn (array $line) => ($line['debit'] ?? 0) > 0 || ($line['credit'] ?? 0) > 0)),
            $actor->id, $date,
        );
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function assertAccountsExist(string $tenantId, array $codes): void
    {
        $codes = array_values(array_unique($codes));
        $missing = array_diff($codes, Account::query()->where('tenant_id', $tenantId)->whereIn('code', $codes)->pluck('code')->all());

        if ($missing !== []) {
            throw new AccountingRuleException('These accounts do not exist in the chart of accounts: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function audit(User $actor, Loan $loan, string $operation, ?array $previous, ?array $new): void
    {
        $this->auditLog->record([
            'module' => 'accounting',
            'entity_type' => 'Loan',
            'entity_id' => $loan->id,
            'operation' => $operation,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'tenant_id' => $loan->tenant_id,
            'prev_values' => $previous,
            'new_values' => $new,
        ]);
    }
}
