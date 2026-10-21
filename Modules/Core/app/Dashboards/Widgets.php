<?php

namespace Modules\Core\Dashboards;

/**
 * Builds the pieces a dashboard is made of, in one shape every department shares, so a client can draw any of them.
 */
final class Widgets
{
    /**
     * A headline number, optionally with the same number for the previous period to show the change.
     *
     * @param  'count'|'currency'|'percent'|'cartons'|'days'|'hours'  $unit
     * @return array<string, mixed>
     */
    public static function kpi(string $key, string $label, int|float $value, int|float|null $previous = null, string $unit = 'count', ?string $hint = null): array
    {
        $change = null;
        $trend = null;

        if ($previous !== null) {
            $change = $previous == 0 ? ($value == 0 ? 0.0 : null) : round(($value - $previous) / abs($previous) * 100, 1);
            $trend = $value > $previous ? 'up' : ($value < $previous ? 'down' : 'flat');
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => is_float($value) ? round($value, 2) : $value,
            'previous' => $previous === null ? null : (is_float($previous) ? round($previous, 2) : $previous),
            'change_pct' => $change,
            'trend' => $trend,
            'unit' => $unit,
            'hint' => $hint,
        ];
    }

    /**
     * A series to draw: one point per label.
     *
     * @param  'line'|'bar'|'pie'|'donut'|'area'  $type
     * @param  array<int, array{label: string, value: int|float}>  $points
     * @return array<string, mixed>
     */
    public static function chart(string $key, string $title, string $type, array $points, string $unit = 'count'): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'type' => $type,
            'unit' => $unit,
            'series' => array_map(fn (array $point) => ['label' => (string) $point['label'], 'value' => is_float($point['value']) ? round($point['value'], 2) : $point['value']], array_values($points)),
        ];
    }

    /**
     * A short ranked list.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function table(string $key, string $title, array $columns, array $rows): array
    {
        return ['key' => $key, 'title' => $title, 'columns' => $columns, 'rows' => array_values($rows)];
    }

    /**
     * Turns rows of `[date => value]` into one point per day of the period, with zero for the days without a row.
     *
     * @param  iterable<string, int|float>  $byDate  Keyed by `Y-m-d`.
     * @return array<int, array{label: string, value: int|float}>
     */
    public static function daily(DashboardPeriod $period, iterable $byDate): array
    {
        $values = collect($byDate)->all();
        $points = [];

        for ($day = $period->from->startOfDay(); $day <= $period->to; $day = $day->addDay()) {
            $points[] = ['label' => $day->toDateString(), 'value' => $values[$day->toDateString()] ?? 0];
        }

        return $points;
    }
}
