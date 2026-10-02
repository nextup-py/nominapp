<?php

namespace App\Exports;

use App\Models\Warning;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Exporta amonestaciones laborales a Excel. */
class WarningsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * Consulta base con eager-load de relaciones necesarias.
     *
     * @return Builder
     */
    public function query()
    {
        return Warning::with(['employee', 'issuedBy'])
            ->orderBy('issued_at', 'desc');
    }

    /**
     * Encabezados de columna del archivo.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Empleado',
            'CI',
            'Tipo',
            'Motivo',
            'Descripción',
            'Fecha de emisión',
            'Emitida por',
            'Días de suspensión',
            'Inicio de suspensión',
            'Observaciones',
            'Creado',
            'Editado',
        ];
    }

    /**
     * Transforma una amonestación en fila de datos para el Excel.
     *
     * @param  Warning  $warning
     * @return array<int, mixed>
     */
    public function map($warning): array
    {
        return [
            $warning->employee->full_name,
            $warning->employee->ci,
            Warning::getTypeLabel($warning->type),
            Warning::getReasonLabel($warning->reason),
            $warning->description,
            $warning->issued_at->format('d/m/Y'),
            $warning->issuedBy->name,
            $warning->suspension_days > 0 ? $warning->suspension_days : '',
            $warning->suspension_start_date?->format('d/m/Y') ?? '',
            $warning->notes ?? '',
            $warning->created_at->format('d/m/Y H:i'),
            $warning->updated_at->format('d/m/Y H:i'),
        ];
    }

    /**
     * Aplica estilos al encabezado.
     *
     * @return array<int|string, mixed>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
