<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One table of statistics rows, used both as a worksheet and as a CSV file.
 */
class RowsSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<array<int, scalar|null>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function array(): array
    {
        return array_map(fn (array $row) => array_map(self::safeCell(...), $row), $this->rows);
    }

    /**
     * Free text (a price-change reason, a user or vehicle name) that starts with = + - @ or a
     * tab/CR would be saved as a formula in the XLSX and run as one when Excel opens the CSV.
     * A leading apostrophe makes Excel show it as plain text. Numbers, including negative ones
     * like "-60.00", are left alone.
     */
    private static function safeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
