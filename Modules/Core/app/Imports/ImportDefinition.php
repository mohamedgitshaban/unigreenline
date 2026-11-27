<?php

namespace Modules\Core\Imports;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\User;

/**
 * Describes one importable entity to SpreadsheetImportService: which
 * columns the sheet has, how a row is validated, and how a validated row
 * becomes (or updates) a record.
 */
abstract class ImportDefinition
{
    /** Hard cap so one upload can't hold a transaction open for minutes. */
    public const MAX_ROWS = 5000;

    /**
     * Whether the sheet being imported is laid out like the company's own workbook rather than like this
     * entity's downloadable template. Set by prepare(); some definitions fill in what the workbook leaves
     * out (its "NEEDED" cells) only in that case, and stay strict for template uploads.
     */
    protected bool $workbookFormat = false;

    public function __construct(protected readonly User $user) {}

    /**
     * Heading of every column the template carries, in order.
     *
     * @return array<int, string>
     */
    abstract public function columns(): array;

    /**
     * Headings that must exist in the sheet (a subset of columns()).
     *
     * @return array<int, string>
     */
    abstract public function requiredColumns(): array;

    /**
     * One example row for the downloadable template.
     *
     * @return array<int, mixed>
     */
    abstract public function exampleRow(): array;

    /**
     * Validation rules keyed by slugged column name.
     *
     * @return array<string, array<int, mixed>>
     */
    abstract public function rules(): array;

    /**
     * Names of the workbook sheet this entity's rows live on, best match first (ignoring case and spacing).
     * Only used when the uploaded file is a workbook with several sheets; otherwise the first sheet is read.
     *
     * @return array<int, string>
     */
    public function sheetNames(): array
    {
        return [];
    }

    /**
     * Headings (slugged) of another layout that mean one of this entity's columns: heading => column.
     * Lets the company's own workbook ("acc.name", "Customer Code") be read next to the template's headings.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Turns the sheet's rows into the records to validate and persist. Gets the rows keyed by their sheet
     * row number (headings already mapped to this entity's columns, "NEEDED" cells already blank) and the
     * sheet's own slugged headings; returns the rows to import, still keyed by a sheet row number so errors
     * can point at it. Where the layout has one record per row this does little more than fill gaps.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $headings
     * @return array<int, array<string, mixed>>
     */
    public function prepare(array $rows, array $headings): array
    {
        return $rows;
    }

    /**
     * Slugged columns that hold dates, so Excel serial numbers can be turned back into dates.
     *
     * @return array<int, string>
     */
    public function dateColumns(): array
    {
        return [];
    }

    /**
     * Writes a validated row. Returns 'created', 'updated' or 'skipped'.
     *
     * @param  array<string, mixed>  $row
     */
    abstract public function persist(array $row, bool $updateExisting): string;

    /**
     * Module name used for the audit-log entry, e.g. "crm".
     */
    abstract public function module(): string;

    /**
     * Entity label used in the audit log and error messages, e.g. "Customer".
     */
    abstract public function entity(): string;

    /**
     * Slug a heading the way Maatwebsite does, so definitions can be written against template headings.
     */
    public static function slug(string $heading): string
    {
        return str($heading)->slug('_')->toString();
    }

    /**
     * The column a slugged sheet heading stands for in this entity.
     */
    public function column(string $slug): string
    {
        return $this->aliases()[$slug] ?? $slug;
    }

    /**
     * Creates the record matched by $match or, when it already exists,
     * updates it (only if $updateExisting) with the non-blank $values.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match  Includes tenant_id.
     * @param  array<string, mixed>  $values
     */
    protected function upsertRecord(string $model, array $match, array $values, bool $updateExisting): string
    {
        $values = array_filter($values, fn (mixed $value) => $value !== null);
        $existing = $model::query()->where($match)->first();

        if ($existing === null) {
            $model::create($match + $values);

            return 'created';
        }

        if (! $updateExisting) {
            return 'skipped';
        }

        $existing->fill($values);
        $changed = $existing->isDirty();
        $existing->save();

        return $changed ? 'updated' : 'skipped';
    }

    protected function toBool(?string $value): ?bool
    {
        return $value === null ? null : in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true);
    }
}
