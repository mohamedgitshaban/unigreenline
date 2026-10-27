<?php

namespace Modules\Core\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Middleware\AuthorizeOrLookup;
use Modules\Core\Http\Resources\LookupResource;

/**
 * The slim answer of a list route declared `->canOrLookup(...)` to a user who lacks its permission:
 * `{ id, name }` per record, paginated like the full list so a dropdown reads it the same way.
 *
 * Call it first thing in index(), with the query already limited to the records that user may pick
 * (the tenant, plus any ownership rule). Only `?search=` (on the name) and `?per_page=` are honoured:
 * `filter[...]` and sorting on other columns would let a caller probe fields they cannot see, and
 * exporting needs the resource's own export permission, so neither applies here.
 */
trait ServesLookups
{
    protected function isLookupOnly(Request $request): bool
    {
        return AuthorizeOrLookup::isLookupOnly($request);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function lookupResponse(Request $request, Builder $query, int $defaultPerPage = 15): AnonymousResourceCollection
    {
        $validated = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $name = $query->qualifyColumn('name');

        if (filled($validated['search'] ?? null)) {
            $query->where($name, 'like', '%'.addcslashes($validated['search'], '\\%_').'%');
        }

        $records = $query
            ->select($query->qualifyColumn('id'), $name)
            ->orderBy($name)
            ->paginate($request->integer('per_page', $defaultPerPage))
            ->withQueryString();

        return LookupResource::collection($records);
    }
}
