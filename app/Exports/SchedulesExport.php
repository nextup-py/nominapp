<?php

namespace App\Exports;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Exporta el listado de horarios con sus días activos y empleados asignados. */
class SchedulesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<int, int>|null  $ids  Horarios a exportar (los del listado filtrado); null = todos.
     */
    public function __construct(protected ?array $ids = null) {}

    /**
     * Query base con relaciones necesarias para el mapeo.
     *
     * @return Builder
     */
    public function query()
    {
        return Schedule::query()
            ->with('days.breaks')
            ->withCount('currentEmployees')
            ->when($this->ids !== null, fn ($query) => $query->whereIn('schedules.id', $this->ids))
            ->orderBy('name');
    }

    /**
     * Encabezados de columna del archivo Excel.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Nombre',
            'Tipo de Jornada',
            'Estado',
            'Resumen semanal',
            'Empleados Asignados',
            'Descripción',
            'Creado',
        ];
    }

    /**
     * Mapea cada horario a una fila del Excel.
     *
     * @param  Schedule  $schedule
     * @return array<int, mixed>
     */
    public function map($schedule): array
    {
        return [
            $schedule->name,
            Schedule::getShiftTypeLabels()[$schedule->shift_type] ?? $schedule->shift_type,
            $schedule->is_active ? 'Activo' : 'Inactivo',
            $schedule->weeklySummaryLabel(),
            $schedule->current_employees_count,
            $schedule->description ?? '—',
            $schedule->created_at->format('d/m/Y'),
        ];
    }

    /**
     * Aplica negrita a la fila de encabezados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
