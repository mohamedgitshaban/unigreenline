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
 * Gated on `{module}.export` specifically — spec §2's eight capability
 * types treat Export as distinct from View, matching how
 * `GET /reports/sales|inventory` already require `analytics.export`
 * (Step 8) rather than piggybacking on the view permission.
 */
trait Exportable
{
    /**
     * @param  string|array<int, string>  $permission  A single permission, or a list of alternatives (any one suffices) — matching endpoints like Invoices that already accept `sales.*` OR `accounting.*`.
     * @param  array<int, string>  $headings
     * @param  Closure(mixed): array<int, mixed>  $rowMapper  Maps one row of the query's results to a plain array matching $headings' order.
     */
    protected function exportIfRequested(
        Request $request,
        string|array $permission,
        Builder $query,
        array $headings,
        Closure $rowMapper,
        string $filename,
    ): ?BinaryFileResponse {
        $format = $request->query('export');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return null;
        }

        $permissions = is_array($permission) ? $permission : [$permission];
        abort_unless($request->user()->canAny($permissions), 403);

        $rows = $query->get()->map($rowMapper)->values();

        $timestamped = $filename.'-'.now()->format('Y-m-d').'.'.$format;

        return Excel::download(
            new GenericExport($headings, $rows),
            $timestamped,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
