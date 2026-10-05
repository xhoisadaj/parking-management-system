<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel workbook: one sheet per statistics table.
 */
class StatisticsWorkbook implements WithMultipleSheets
{
    /** @param  list<RowsSheet>  $sheets */
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}
