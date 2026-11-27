<?php

namespace App\Imports;

use Closure;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Counterpart to GenericExport: reads one sheet of an xlsx/xls/csv file into rows keyed by slugged
 * heading ("Credit Limit" => credit_limit). What the rows mean is decided by an ImportDefinition, not here.
 *
 * Workbooks often hold several tables and a title above the real headings, so the caller says which sheet
 * to read (by name, first match wins; the first sheet when none matches) and how to recognise the heading
 * row (the first of the top rows it accepts; the very first row when none does).
 */
class RowsImport
{
    /** How far down the sheet to look for the heading row. */
    private const HEADING_SEARCH_ROWS = 15;

    /** @var list<string> Slugged headings of the sheet that was read, in order. */
    public array $headings = [];

    /** @var array<int, array<string, mixed>> Sheet row number => cells keyed by slugged heading; blank rows are left out. */
    public array $rows = [];

    /**
     * @param  array<int, string>  $sheetNames  Sheets to look for, best match first.
     * @param  Closure(list<string>): bool  $isHeadingRow  Whether a row's slugged headings are the ones wanted.
     * @param  Closure(string): string  $slug
     */
    public function read(string $path, string $extension, array $sheetNames, Closure $isHeadingRow, Closure $slug): void
    {
        $reader = $this->readerFor($path, $extension);
        $reader->setReadDataOnly(true);

        $sheet = $this->sheetToLoad($reader, $path, $sheetNames);

        if ($sheet !== null) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $cells = $reader->load($path)->getSheet(0)->toArray(null, false, false, false);

        $headingIndex = 0;

        foreach (array_slice($cells, 0, self::HEADING_SEARCH_ROWS, true) as $index => $row) {
            if ($isHeadingRow($this->slugged($row, $slug))) {
                $headingIndex = $index;

                break;
            }
        }

        $headings = $this->slugged($cells[$headingIndex] ?? [], $slug);
        $this->headings = array_values(array_filter($headings, fn (string $heading) => $heading !== ''));

        foreach (array_slice($cells, $headingIndex + 1, null, true) as $index => $row) {
            $record = [];

            foreach ($headings as $column => $heading) {
                if ($heading !== '') {
                    $record[$heading] ??= $row[$column] ?? null;
                }
            }

            $this->rows[$index + 1] = $record;
        }
    }

    private function readerFor(string $path, string $extension): IReader
    {
        return match (strtolower($extension)) {
            'xlsx' => new Xlsx,
            'xls' => new Xls,
            'csv', 'txt' => new Csv,
            default => IOFactory::createReaderForFile($path),
        };
    }

    /**
     * The name of the sheet to load, or null for the first one (also for csv, which has only one).
     *
     * @param  array<int, string>  $sheetNames
     */
    private function sheetToLoad(IReader $reader, string $path, array $sheetNames): ?string
    {
        if ($sheetNames === [] || $reader instanceof Csv) {
            return null;
        }

        $available = [];

        foreach ($reader->listWorksheetNames($path) as $name) {
            $available[$this->normalizeName($name)] = $name;
        }

        foreach ($sheetNames as $wanted) {
            if (isset($available[$this->normalizeName($wanted)])) {
                return $available[$this->normalizeName($wanted)];
            }
        }

        return null;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  Closure(string): string  $slug
     * @return array<int, string>
     */
    private function slugged(array $row, Closure $slug): array
    {
        return array_map(fn (mixed $cell) => $cell === null ? '' : $slug((string) $cell), $row);
    }
}
