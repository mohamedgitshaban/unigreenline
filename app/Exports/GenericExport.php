<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A single reusable export shape for every list endpoint's `?export=` option
 * (Modules\Core\Http\Controllers\Concerns\Exportable) — column headings plus
 * pre-mapped row arrays, rather than one dedicated Export class per
 * resource. Callers map rows to plain arrays themselves (matching
 * $headings order) since the source models vary per endpoint; this class
 * only knows how to hand that data to PhpSpreadsheet.
 */
class GenericExport implements FromCollection, WithHeadings
{
    /**
     * @param  array<int, string>  $headings
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly Collection $rows,
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function collection(): Collection
    {
        return $this->rows;
    }
}
