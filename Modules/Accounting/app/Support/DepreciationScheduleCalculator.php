<?php

namespace Modules\Accounting\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Works out the depreciation board of an asset: one line per period with the amount, the total so far and what the asset is
 * still worth. Pure arithmetic — it reads nothing and writes nothing.
 *
 * Periods run on calendar months: with a 3-month period an asset bought in March has its first period March–May, the next
 * June–August, and each line is dated on the last day of its period.
 *
 *  - straight_line: the same amount every period.
 *  - declining_balance: each period takes `declining_factor` of what is still to be depreciated (the last period takes the rest).
 *  - declining_then_straight: like declining, but never less than what spreading the remainder evenly over the periods left would give.
 *
 * With `prorata` the asset's life is exactly `duration_periods` periods counted from the day it starts, so a part-period at the
 * start (and one at the end) count only for the days they cover — a period with 16 of its 31 days weighs 16/31 of a full one —
 * and there is one more line than periods. Full periods always weigh the same.
 */
final class DepreciationScheduleCalculator
{
    public const METHODS = ['straight_line', 'declining_balance', 'declining_then_straight'];

    /**
     * @param  array{original_value: float|int|string, salvage_value?: float|int|string, method: string, duration_periods: int, period_months: int, declining_factor?: float|int|string, prorata?: bool, start_date: string|\DateTimeInterface}  $asset
     * @return array<int, array{sequence: int, period_start: string, depreciation_date: string, amount: float, accumulated_after: float, book_value_after: float}>
     */
    public static function lines(array $asset): array
    {
        $original = round((float) $asset['original_value'], 2);
        $salvage = round((float) ($asset['salvage_value'] ?? 0), 2);
        $method = $asset['method'];
        $periods = (int) $asset['duration_periods'];
        $months = (int) $asset['period_months'];
        $factor = (float) ($asset['declining_factor'] ?? 0.3);
        $prorata = (bool) ($asset['prorata'] ?? false);

        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unknown depreciation method {$method}.");
        }

        if ($periods < 1 || $months < 1) {
            throw new InvalidArgumentException('An asset needs at least one period of at least one month.');
        }

        $base = round($original - $salvage, 2);

        if ($base <= 0) {
            return [];
        }

        $slices = self::slices(CarbonImmutable::parse($asset['start_date'])->startOfDay(), $periods, $months, $prorata);
        $totalShare = max(self::shareLeft($slices, 0, $prorata), 0.000001);
        $remaining = $base;
        $accumulated = 0.0;
        $lines = [];
        $count = count($slices);

        foreach ($slices as $index => $slice) {
            $left = $count - $index;
            $share = $prorata ? $slice['days'] / $slice['full_days'] : 1.0;

            $amount = match ($method) {
                'straight_line' => $base * $share / $totalShare,
                'declining_balance' => $remaining * $factor * $share,
                'declining_then_straight' => max($remaining * $factor * $share, $remaining * $share / self::shareLeft($slices, $index, $prorata)),
            };

            $amount = $left === 1 ? $remaining : min(round($amount, 2), $remaining);
            $remaining = round($remaining - $amount, 2);
            $accumulated = round($accumulated + $amount, 2);

            $lines[] = [
                'sequence' => $index + 1,
                'period_start' => $slice['start']->toDateString(),
                'depreciation_date' => $slice['end']->toDateString(),
                'amount' => $amount,
                'accumulated_after' => $accumulated,
                'book_value_after' => round($original - $accumulated, 2),
            ];
        }

        return $lines;
    }

    /**
     * The periods of the asset's life: first day, last day, the days the asset is in use during it and the days in the full period.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, days: int, full_days: int}>
     */
    private static function slices(CarbonImmutable $start, int $periods, int $months, bool $prorata): array
    {
        $lifeEnd = $start->addMonthsNoOverflow($periods * $months)->subDay();
        $slices = [];

        for ($k = 0; $k < $periods + 1; $k++) {
            $first = $start->startOfMonth()->addMonthsNoOverflow($k * $months);
            $last = $start->startOfMonth()->addMonthsNoOverflow(($k + 1) * $months)->subDay();

            if (! $prorata) {
                if ($k === $periods) {
                    break;
                }

                $slices[] = ['start' => $k === 0 ? $start : $first, 'end' => $last, 'days' => 1, 'full_days' => 1];

                continue;
            }

            $from = $first->max($start);
            $to = $last->min($lifeEnd);

            if ($from->gt($to)) {
                break;
            }

            $slices[] = ['start' => $from, 'end' => $to, 'days' => (int) $from->diffInDays($to) + 1, 'full_days' => (int) $first->diffInDays($last) + 1];
        }

        return $slices;
    }

    /**
     * How many full-period equivalents are left from this period on (so an even spread of the remainder respects part-periods).
     *
     * @param  array<int, array{days: int, full_days: int}>  $slices
     */
    private static function shareLeft(array $slices, int $from, bool $prorata): float
    {
        $sum = 0.0;

        for ($i = $from, $n = count($slices); $i < $n; $i++) {
            $sum += $prorata ? $slices[$i]['days'] / $slices[$i]['full_days'] : 1.0;
        }

        return max($sum, 0.000001);
    }
}
