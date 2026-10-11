<?php

namespace Modules\Core\Http\Controllers\Concerns;

use App\Exports\GenericExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Services\SpreadsheetImportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `POST /{resource}/import` (multipart `file`, optional `update_existing`)
 * and `GET /{resource}/import-template`, added uniformly rather than
 * per-controller. The caller authorizes (the `create` ability) before
 * calling either, then supplies the entity's ImportDefinition.
 */
trait ImportsSpreadsheets
{
    protected function runImport(Request $request, ImportDefinition $definition): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'update_existing' => ['sometimes', 'boolean'],
        ]);

        $counts = app(SpreadsheetImportService::class)->run(
            $request->file('file'),
            $definition,
            $request->user(),
            $request->boolean('update_existing'),
            $request->ip(),
        );

        return response()->json([
            'message' => "Imported {$counts['created']} new and updated {$counts['updated']} existing record(s).",
            ...$counts,
            'errors' => [],
        ]);
    }

    protected function templateResponse(ImportDefinition $definition, string $filename): BinaryFileResponse
    {
        return Excel::download(
            new GenericExport($definition->columns(), collect([$definition->exampleRow()])),
            $filename.'-import-template.xlsx',
            ExcelFormat::XLSX,
        );
    }
}
