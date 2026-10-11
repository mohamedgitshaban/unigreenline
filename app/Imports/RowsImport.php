<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Counterpart to GenericExport: reads the first sheet of an xlsx/csv file
 * into rows keyed by slugged heading ("Credit Limit" => credit_limit). What
 * the rows mean is decided by an ImportDefinition, not here.
 */
class RowsImport implements ToCollection, WithHeadingRow
{
    /** @var Collection<int, Collection<string, mixed>> */
    public Collection $rows;

    public function collection(Collection $collection): void
    {
        $this->rows = $collection;
    }
}
