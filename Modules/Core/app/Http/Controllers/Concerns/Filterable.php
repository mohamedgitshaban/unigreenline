<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * `?search=` and `?filter[...]=` on any index endpoint, added uniformly
 * rather than per-controller. Call it before applySorting() and
 * exportIfRequested() so exports share the same filtered rows.
 *
 * - `search` is a case-insensitive "contains" match OR'ed across the
 *   controller-supplied $searchable columns; `relation.column` entries
 *   search a related model (e.g. `customer.name`).
 * - `filter[column]=value` is an exact match; `filter[column][op]=value`
 *   applies an operator from FILTER_OPERATORS. `in`/`not_in` take a
 *   comma-separated list, `null` takes true/false.
 *
 * Like Sortable, filterable columns are the model table's real columns minus
 * the model's $hidden — anything else is a 422, so raw keys never reach SQL.
 * Values are always bound. A date-only value against a timestamp column
 * compares by date, so `filter[created_at][lte]=2026-10-01` includes that day.
 */
trait Filterable
{
    /**
     * @var array<int, string>
     */
    private const FILTER_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'not_in', 'null'];

    /**
     * @param  array<int, string>  $searchable  Columns `?search=` matches against; `relation.column` for a related model's column.
     */
    protected function applyFilters(Request $request, Builder $query, array $searchable = []): Builder
    {
        $model = $query->getModel();

        /** @var array<string, string> $columnTypes column name => database type name */
        $columnTypes = collect(Schema::getColumns($model->getTable()))
            ->pluck('type_name', 'name')
            ->except($model->getHidden())
            ->all();

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array:'.implode(',', array_keys($columnTypes))],
        ]);

        if (filled($validated['search'] ?? null) && $searchable !== []) {
            $pattern = '%'.$this->escapeLike($validated['search']).'%';

            $query->where(function (Builder $query) use ($searchable, $pattern) {
                foreach ($searchable as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $relatedColumn] = explode('.', $column, 2);
                        $query->orWhereRelation($relation, $relatedColumn, 'like', $pattern);
                    } else {
                        $query->orWhere($query->qualifyColumn($column), 'like', $pattern);
                    }
                }
            });
        }

        foreach ($validated['filter'] ?? [] as $column => $conditions) {
            $conditions = is_array($conditions) ? $conditions : ['eq' => $conditions];

            foreach ($conditions as $operator => $value) {
                // Empty inputs (an untouched filter field) are ignored, not matched against NULL.
                if (blank($value)) {
                    continue;
                }

                $this->applyFilterCondition($query, $column, $columnTypes[$column], (string) $operator, $value);
            }
        }

        return $query;
    }

    private function applyFilterCondition(Builder $query, string $column, string $type, string $operator, mixed $value): void
    {
        $key = "filter.{$column}";

        if (! in_array($operator, self::FILTER_OPERATORS, true)) {
            throw ValidationException::withMessages([
                $key => "Unknown filter operator [{$operator}]. Allowed: ".implode(', ', self::FILTER_OPERATORS).'.',
            ]);
        }

        if (in_array($operator, ['in', 'not_in'], true)) {
            $values = is_array($value) ? $value : explode(',', (string) $value);

            if (array_filter($values, fn (mixed $item) => ! is_scalar($item)) !== []) {
                throw ValidationException::withMessages([$key => 'Filter values must be plain values.']);
            }

            $values = array_map(fn (mixed $item) => $this->normalizeFilterValue(trim((string) $item), $type), $values);

            $operator === 'in'
                ? $query->whereIn($query->qualifyColumn($column), $values)
                : $query->whereNotIn($query->qualifyColumn($column), $values);

            return;
        }

        if (! is_scalar($value) && $value !== null) {
            throw ValidationException::withMessages([$key => 'Filter values must be plain values.']);
        }

        $qualified = $query->qualifyColumn($column);

        if ($operator === 'null') {
            $isNull = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($isNull === null) {
                throw ValidationException::withMessages([$key => 'The null operator accepts true or false.']);
            }

            $isNull ? $query->whereNull($qualified) : $query->whereNotNull($qualified);

            return;
        }

        if ($operator === 'like') {
            $query->where($qualified, 'like', '%'.$this->escapeLike((string) $value).'%');

            return;
        }

        $sqlOperator = ['eq' => '=', 'ne' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator];
        $value = $this->normalizeFilterValue((string) $value, $type);

        if (in_array($type, ['timestamp', 'datetime'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $query->whereDate($qualified, $sqlOperator, $value);

            return;
        }

        $query->where($qualified, $sqlOperator, $value);
    }

    /**
     * Booleans are stored as tinyint, so `true`/`false` become 1/0 there.
     */
    private function normalizeFilterValue(string $value, string $type): string
    {
        if ($type === 'tinyint' && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true' ? '1' : '0';
        }

        return $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
