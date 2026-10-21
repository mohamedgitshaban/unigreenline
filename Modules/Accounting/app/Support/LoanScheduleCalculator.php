<?php

namespace Modules\Accounting\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Works out the repayment schedule (the amortization table) of a loan: for every instalment, when it falls due, how much of it is
 * interest, how much repays the loan and what is still owed afterwards. Pure arithmetic.
 *
 *  - annuity: the same payment every period (interest falls, principal grows) — the usual bank loan.
 *  - fixed_principal: the same principal every period, so the payment falls as the interest does.
 *  - interest_only: only interest each period and the whole principal with the last instalment.
 *
 * `grace_periods` at the start pay interest only (annuity and fixed_principal). `$level` keeps a given payment (annuity) or
 * principal (fixed_principal) instead of working one out — used when part of a loan is repaid early and the instalment stays. Interest of a period is the opening balance times
 * the annual rate times the months in the period over twelve. The last instalment always clears the balance to the cent.
 */
final class LoanScheduleCalculator
{
    public const METHODS = ['annuity', 'fixed_principal', 'interest_only'];

    /**
     * @return array<int, array{sequence: int, due_date: string, opening_balance: float, principal_amount: float, interest_amount: float, payment_amount: float, closing_balance: float}>
     */
    public static function schedule(float $principal, float $annualRatePct, string $method, int $periods, int $periodMonths, string|\DateTimeInterface $firstDueDate, int $gracePeriods = 0, int $firstSequence = 1, ?float $level = null): array
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown loan method {$method}.");
        }

        if ($periods < 1 || $periodMonths < 1 || $gracePeriods < 0 || $gracePeriods >= $periods) {
            throw new InvalidArgumentException('A loan needs at least one period and fewer grace periods than periods.');
        }

        $principal = round($principal, 2);

        if ($principal <= 0) {
            return [];
        }

        $rate = self::periodRate($annualRatePct, $periodMonths);
        $first = CarbonImmutable::parse($firstDueDate)->startOfDay();
        $repaying = $periods - $gracePeriods;
        $payment = $method === 'annuity' ? ($level ?? self::annuityPayment($principal, $rate, $repaying)) : 0.0;
        $fixedPrincipal = $method === 'fixed_principal' ? ($level ?? round($principal / $repaying, 2)) : 0.0;
        $balance = $principal;
        $rows = [];

        for ($k = 1; $k <= $periods; $k++) {
            $interest = round($balance * $rate, 2);
            $inGrace = $k <= $gracePeriods;
            $last = $k === $periods;

            $due = match (true) {
                $last => $balance,
                $inGrace => 0.0,
                $method === 'annuity' => min($balance, round($payment - $interest, 2)),
                $method === 'fixed_principal' => min($balance, $fixedPrincipal),
                default => 0.0,
            };

            $closing = round($balance - $due, 2);

            $rows[] = [
                'sequence' => $firstSequence + $k - 1,
                'due_date' => $first->addMonthsNoOverflow(($k - 1) * $periodMonths)->toDateString(),
                'opening_balance' => $balance,
                'principal_amount' => $due,
                'interest_amount' => $interest,
                'payment_amount' => round($due + $interest, 2),
                'closing_balance' => $closing,
            ];

            $balance = $closing;
        }

        return $rows;
    }

    /**
     * Interest rate for one period.
     */
    public static function periodRate(float $annualRatePct, int $periodMonths): float
    {
        return $annualRatePct / 100 * $periodMonths / 12;
    }

    /**
     * The level payment that repays `$principal` in `$periods` periods at `$rate` per period.
     */
    public static function annuityPayment(float $principal, float $rate, int $periods): float
    {
        if ($rate == 0.0) {
            return round($principal / $periods, 2);
        }

        return round($principal * $rate / (1 - (1 + $rate) ** -$periods), 2);
    }

    /**
     * How many periods it takes to repay `$principal` at `$rate` per period with level payments of `$payment`
     * (used when a part of the loan is repaid early and the payment is kept).
     */
    public static function periodsFor(float $principal, float $rate, float $payment): int
    {
        if ($payment <= 0) {
            throw new InvalidArgumentException('The payment must be positive.');
        }

        if ($rate == 0.0) {
            return max(1, (int) ceil($principal / $payment));
        }

        if ($payment <= $principal * $rate) {
            throw new InvalidArgumentException('The payment does not even cover the interest.');
        }

        return max(1, (int) ceil(-log(1 - $principal * $rate / $payment) / log(1 + $rate) - 1e-9));
    }
}
