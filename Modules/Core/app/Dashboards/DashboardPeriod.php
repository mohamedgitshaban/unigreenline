<?php

namespace Modules\Core\Dashboards;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The stretch of time a dashboard looks at, and the stretch of equal length right before it (to show change).
 * Chosen with `?period=` — today, yesterday, 7d, 30d, month (default), last_month, quarter, year, or custom with `from` and `to`.
 */
final class DashboardPeriod
{
    public const CHOICES = ['today', 'yesterday', '7d', '30d', 'month', 'last_month', 'quarter', 'year', 'custom'];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(self::CHOICES)],
            'from' => ['required_if:period,custom', 'date'],
            'to' => ['required_if:period,custom', 'date', 'after_or_equal:from'],
        ]);

        return self::named($data['period'] ?? 'month', $data['from'] ?? null, $data['to'] ?? null);
    }

    public static function named(string $period, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now();

        [$start, $end] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };

        return new self($start, $end, $period);
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * The period of the same length that ended the day before this one began.
     */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->subDays($days)->startOfDay(), $this->from->subDay()->endOfDay(), 'previous');
    }

    /**
     * @return array{from: string, to: string, label: string, days: int, previous: array{from: string, to: string}}
     */
    public function toArray(): array
    {
        $previous = $this->previous();

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label,
            'days' => $this->days(),
            'previous' => ['from' => $previous->from->toDateString(), 'to' => $previous->to->toDateString()],
        ];
    }
}
