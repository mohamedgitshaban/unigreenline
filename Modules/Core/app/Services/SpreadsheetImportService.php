<?php

namespace Modules\Core\Services;

use App\Imports\RowsImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
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
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function run(UploadedFile $file, ImportDefinition $definition, User $user, bool $updateExisting = false, ?string $ipAddress = null): array
    {
        $rows = $this->readRows($file, $definition);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $sheetRow = $index + 2; // heading row is row 1
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
                throw new ImportFailedException('The file has '.count($errors).' invalid row(s). Nothing was imported.', $errors);
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
     * @return array<int, array<string, mixed>>
     */
    private function readRows(UploadedFile $file, ImportDefinition $definition): array
    {
        try {
            $import = new RowsImport;
            Excel::import($import, $file);
        } catch (Throwable) {
            throw new ImportFailedException('The file could not be read. Upload a valid .xlsx or .csv file.');
        }

        $rows = $import->rows->map(fn ($row) => $this->normalize($row->toArray(), $definition))
            ->filter(fn (array $row) => array_filter($row, fn ($value) => $value !== null) !== [])
            ->values();

        if ($rows->isEmpty()) {
            throw new ImportFailedException('The file has no data rows.');
        }

        if ($rows->count() > ImportDefinition::MAX_ROWS) {
            throw new ImportFailedException('The file has more than '.ImportDefinition::MAX_ROWS.' rows. Split it into smaller files.');
        }

        $missing = array_values(array_filter(
            array_map([ImportDefinition::class, 'slug'], $definition->requiredColumns()),
            fn (string $column) => ! array_key_exists($column, $rows->first()),
        ));

        if ($missing !== []) {
            throw new ImportFailedException('Missing required column(s): '.implode(', ', $missing).'.');
        }

        return $rows->all();
    }

    /**
     * Cells arrive typed by Excel (phone numbers as ints, dates as serials);
     * validation and persistence want plain trimmed strings, with blanks as null.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, ImportDefinition $definition): array
    {
        $dateColumns = $definition->dateColumns();
        $normalized = [];

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                $normalized[$column] = null;

                continue;
            }

            if (in_array($column, $dateColumns, true) && is_numeric($value)) {
                $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            $normalized[$column] = is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value;
        }

        return $normalized;
    }
}
