<?php

namespace App\Exports;

use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Exporta el listado de turnos con su horario, estado y uso en patrones y turnos puntuales. */
class ShiftTemplatesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /** @var array<int, array{patterns: int, overrides: int}> Uso de cada turno, calculado una sola vez. */
    private array $usage;

    /**
     * @param  array<int, int>|null  $ids  Turnos a exportar (los del listado filtrado); null = todos.
     */
    public function __construct(protected ?array $ids = null)
    {
        $this->usage = ShiftTemplate::usageMap();
    }

    /** Turnos con su empresa, ordenados por nombre. */
    public function query(): Builder
    {
        return ShiftTemplate::query()
            ->with('company')
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('shift_templates.id', $this->ids))
            ->orderBy('name');
    }

    /**
     * Encabezados de columna del archivo Excel.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Turno', 'Empresa', 'Jornada', 'Horario', 'Descanso (min)', 'Estado', 'Patrones', 'Turnos puntuales', 'Notas'];
    }

    /**
     * Mapea cada turno a una fila del Excel.
     *
     * @param  ShiftTemplate  $shift
     * @return array<int, mixed>
     */
    public function map($shift): array
    {
        $usage = $this->usage[$shift->id] ?? ['patterns' => 0, 'overrides' => 0];

        return [
            $shift->name,
            $shift->company?->name ?? '—',
            $shift->is_day_off ? '—' : (ShiftTemplate::getShiftTypeLabels()[$shift->shift_type] ?? $shift->shift_type),
            $shift->scheduleSummary().($shift->crossesMidnight() ? ' (cruza la medianoche)' : ''),
            $shift->is_day_off ? '—' : $shift->break_minutes,
            $shift->is_active ? 'Activo' : 'Inactivo',
            $usage['patterns'],
            $usage['overrides'],
            $shift->notes ?? '—',
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
