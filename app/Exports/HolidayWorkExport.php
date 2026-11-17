<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Exporta el reporte de trabajo en feriado y domingo a Excel (las mismas filas de la pantalla). */
class HolidayWorkExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /** @param  Collection<int, array<string, mixed>>  $rows  Filas de `HolidayWorkReportService::rows()`. */
    public function __construct(private readonly Collection $rows) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Fecha', 'Tipo', 'Empleado', 'Sucursal', 'Horas trabajadas', 'Horas esperadas', 'Horas extra', 'Extra aprobada', 'Horas del turno', 'Valor hora (Gs.)', 'Recargo estimado (Gs.)'];
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row): array => [
            $row['date']->format('d/m/Y'),
            $row['kind'],
            $row['employee'],
            $row['branch'],
            $row['worked'],
            $row['expected'],
            $row['extra'],
            $row['extra_approved'] ? 'Sí' : 'No',
            $row['normal'],
            $row['hourly'] !== null ? round($row['hourly']) : 'Sin contrato mensual',
            $row['premium'] ?? '',
        ]);
    }
}
