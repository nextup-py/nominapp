<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Hoja de Excel genérica a partir de filas ya armadas, con encabezados y título. */
class ArraySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $headings
     */
    public function __construct(private readonly array $rows, private readonly array $headings, private readonly string $title) {}

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
