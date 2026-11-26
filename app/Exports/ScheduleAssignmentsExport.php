<?php

namespace App\Exports;

use App\Models\EmployeeScheduleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta el historial de asignaciones de un horario (vigentes, futuras y finalizadas): quién lo tuvo, desde y hasta
 * cuándo, y quién lo asignó. Respeta los filtros y la búsqueda de la pestaña cuando recibe los ids.
 */
class ScheduleAssignmentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  int  $scheduleId  Horario del que se exporta el historial.
     * @param  array<int, int>|null  $ids  Ids de asignaciones a incluir (null = todas las del horario).
     */
    public function __construct(private int $scheduleId, private ?array $ids = null) {}

    /** Asignaciones del horario, más recientes primero. */
    public function query(): Builder
    {
        return EmployeeScheduleAssignment::query()
            ->where('schedule_id', $this->scheduleId)
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('id', $this->ids))
            ->with(['employee', 'createdBy'])
            ->orderByDesc('valid_from');
    }

    /**
     * Encabezados de columna del archivo Excel.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Apellido', 'Nombre', 'CI', 'Estado', 'Desde', 'Hasta', 'Asignado por', 'Notas'];
    }

    /**
     * Mapea cada asignación a una fila del Excel.
     *
     * @param  EmployeeScheduleAssignment  $assignment
     * @return array<int, mixed>
     */
    public function map($assignment): array
    {
        return [
            $assignment->employee?->last_name ?? 'Empleado eliminado',
            $assignment->employee?->first_name ?? '—',
            $assignment->employee?->ci ?? '—',
            EmployeeScheduleAssignment::getStatusLabels()[$assignment->status()] ?? $assignment->status(),
            $assignment->valid_from?->format('d/m/Y') ?? '—',
            $assignment->valid_until?->format('d/m/Y') ?? 'Sin fecha de fin',
            $assignment->createdBy?->name ?? 'Sin dato',
            $assignment->notes ?? '',
        ];
    }

    /**
     * Aplica negrita a la fila de encabezados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
