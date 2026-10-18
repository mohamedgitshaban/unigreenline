<?php

namespace Modules\Core\Http\Controllers\Concerns;

use App\Exports\GenericExport;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `?export=csv|xlsx` on any index endpoint, added uniformly rather than
 * per-controller. Call this first thing in index(), before ->paginate() —
 * it returns a file download built from the *same* filtered query (every
 * matching row, not just one page) when the query string asks for one, or
 * null otherwise so the caller falls through to its normal JSON response.
 *
 * Exporting needs its own permission, separate from viewing: the one the route
 * declares with `->exportable('{resource}.export')`. A route that does not
 * declare one cannot export (403) — fail closed.
 */
trait Exportable
{
    /**
     * @param  array<int, string>  $headings
     * @param  Closure(mixed): array<int, mixed>  $rowMapper  Maps one row of the query's results to a plain array matching $headings' order.
     */
    protected function exportIfRequested(
        Request $request,
        Builder $query,
        array $headings,
        Closure $rowMapper,
        string $filename,
    ): ?BinaryFileResponse {
        $format = $request->query('export');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return null;
        }

        $permission = $request->route()?->getAction('export_permission');
        abort_unless($permission !== null && $request->user()->can($permission), 403);

        $rows = $query->get()->map($rowMapper)->values();

        $timestamped = $filename.'-'.now()->format('Y-m-d').'.'.$format;

        return Excel::download(
            new GenericExport($headings, $rows),
            $timestamped,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
