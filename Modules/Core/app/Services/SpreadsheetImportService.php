<?php

namespace Modules\Core\Services;

use App\Imports\RowsImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Exceptions\ImportFailedException;
use Modules\Core\Exceptions\ImportRowException;
use Modules\Core\Imports\ImportDefinition;
use Modules\Core\Models\User;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Bulk-creates records from an uploaded xlsx/csv. All-or-nothing: every row
 * is validated and written inside one transaction, and if any row fails the
 * whole import is rolled back and the failing rows (with their sheet row
 * numbers) are reported, so the user fixes the file once and re-uploads it.
 */
class SpreadsheetImportService
{
    /** What the company's workbook writes in a cell it still has to fill in; read as blank. */
    private const PLACEHOLDER = 'NEEDED';

    /** How many row errors the failure message spells out before pointing at the `errors` list. */
    private const MESSAGE_ERROR_LIMIT = 5;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  UploadedFile|string  $file  An upload, or the path of a file on this machine.
     * @return array{created: int, updated: int, skipped: int}
     */
    public function run(UploadedFile|string $file, ImportDefinition $definition, User $user, bool $updateExisting = false, ?string $ipAddress = null): array
    {
        if (is_string($file)) {
            $file = new UploadedFile($file, basename($file), null, null, true);
        }

        $rows = $this->readRows($file, $definition);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $sheetRow => $row) {
                $validator = Validator::make($row, $definition->rules());

                if ($validator->fails()) {
                    $errors[] = ['row' => $sheetRow, 'errors' => $validator->errors()->toArray()];

                    continue;
                }

                try {
                    $counts[$definition->persist($validator->validated(), $updateExisting)]++;
                } catch (ImportRowException $e) {
                    $errors[] = ['row' => $sheetRow, 'errors' => [$e->column => [$e->getMessage()]]];
                }
            }

            if ($errors !== []) {
                throw new ImportFailedException(
                    'The file has '.count($errors).' invalid row(s). Nothing was imported. '.$this->describeErrors($errors),
                    $errors,
                );
            }

            $this->auditLog->record([
                'module' => $definition->module(),
                'entity_type' => $definition->entity(),
                'operation' => 'IMPORT',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'tenant_id' => $user->tenant_id,
                'new_values' => $counts + ['file' => $file->getClientOriginalName()],
                'ip_address' => $ipAddress,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $counts;
    }

    /**
     * One-line summary of the first few row errors, so a client that only
     * shows `message` still tells the user which row failed and why. The full
     * list stays in the response's `errors`.
     *
     * @param  array<int, array{row: int, errors: array<string, array<int, string>>}>  $errors
     */
    private function describeErrors(array $errors): string
    {
        $details = [];

        foreach ($errors as $error) {
            foreach ($error['errors'] as $column => $messages) {
                foreach ($messages as $message) {
                    $details[] = "Row {$error['row']} ({$column}): {$message}";
                }
            }
        }

        $summary = implode(' ', array_slice($details, 0, self::MESSAGE_ERROR_LIMIT));
        $remaining = count($details) - self::MESSAGE_ERROR_LIMIT;

        return $remaining > 0 ? "{$summary} ...and {$remaining} more error(s); see the errors list." : $summary;
    }

    /**
     * @return array<int, array<string, mixed>> The rows to import, keyed by their row number in the sheet.
     */
    private function readRows(UploadedFile $file, ImportDefinition $definition): array
    {
        $required = array_map(fn (string $heading) => $definition->column(ImportDefinition::slug($heading)), $definition->requiredColumns());
        $import = new RowsImport;

        try {
            $import->read(
                $file->getRealPath(),
                $file->getClientOriginalExtension(),
                $definition->sheetNames(),
                fn (array $slugs) => in_array($required[0], array_map($definition->column(...), $slugs), true),
                ImportDefinition::slug(...),
            );
        } catch (Throwable) {
            throw new ImportFailedException('The file could not be read. Upload a valid .xlsx or .csv file.');
        }

        $rows = [];

        foreach ($import->rows as $sheetRow => $cells) {
            $row = $this->normalize($cells, $definition);

            if (array_filter($row, fn ($value) => $value !== null) !== []) {
                $rows[$sheetRow] = $row;
            }
        }

        $rows = $definition->prepare($rows, $import->headings);

        if ($rows === []) {
            throw new ImportFailedException('The file has no data rows.');
        }

        if (count($rows) > ImportDefinition::MAX_ROWS) {
            throw new ImportFailedException('The file has more than '.ImportDefinition::MAX_ROWS.' rows. Split it into smaller files.');
        }

        $first = reset($rows);
        $missing = array_values(array_filter(
            array_map([ImportDefinition::class, 'slug'], $definition->requiredColumns()),
            fn (string $column) => ! array_key_exists($definition->column($column), $first),
        ));

        if ($missing !== []) {
            throw new ImportFailedException('Missing required column(s): '.implode(', ', $missing).'.');
        }

        return $rows;
    }

    /**
     * Cells arrive typed by Excel (phone numbers as ints, dates as serials);
     * validation and persistence want plain trimmed strings, with blanks (and
     * the workbook's "NEEDED" markers) as null. Headings the definition knows
     * under another name are renamed to the column the rules expect.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function normalize(array $cells, ImportDefinition $definition): array
    {
        $dateColumns = $definition->dateColumns();
        $normalized = [];

        foreach ($cells as $heading => $value) {
            $column = $definition->column($heading);

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null || (is_string($value) && strcasecmp($value, self::PLACEHOLDER) === 0)) {
                $normalized[$column] ??= null;

                continue;
            }

            if (in_array($column, $dateColumns, true) && is_numeric($value)) {
                $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            $normalized[$column] ??= is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value;
        }

        return $normalized;
    }
}
