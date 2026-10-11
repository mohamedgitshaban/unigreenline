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
