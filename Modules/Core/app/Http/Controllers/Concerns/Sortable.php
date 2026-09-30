<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * `?sort_by=&sort_dir=` on any index endpoint, added uniformly rather than
 * per-controller. Defaults to `id desc` — ids are ULIDs, so that's newest
 * first. Call it before exportIfRequested() so exports share the order.
 *
 * `sort_by` must be one of the model table's real columns (minus the
 * model's $hidden, e.g. users.password), otherwise 422 — the raw value never
 * reaches SQL. Any other sort gets `id desc` as a tie-breaker so pagination
 * stays stable across pages.
 */
trait Sortable
{
    protected function applySorting(Request $request, Builder $query): Builder
    {
        $model = $query->getModel();

        $sortableColumns = array_values(array_diff(
            Schema::getColumnListing($model->getTable()),
            $model->getHidden(),
        ));

        $validated = $request->validate([
            'sort_by' => ['sometimes', 'string', Rule::in($sortableColumns)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $sortBy = $validated['sort_by'] ?? 'id';

        $query->orderBy($model->qualifyColumn($sortBy), $validated['sort_dir'] ?? 'desc');

        if ($sortBy !== 'id') {
            $query->orderByDesc($model->qualifyColumn('id'));
        }

        return $query;
    }
}
