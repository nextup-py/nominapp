<?php

namespace App\Exports;

use App\Models\Schedule;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta la grilla del Planificador: una fila por empleado con su patrón, una columna por día (turno, franco,
 * vacaciones o licencia) y las horas netas planificadas del rango. Al final, la cobertura por día.
 */
class ShiftPlanExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    private const DAY_NAMES = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    /**
     * @param  array<string, mixed>  $plan  Resultado de `ShiftPlanService::plan()`.
     */
    public function __construct(private array $plan, private Carbon $start, private Carbon $end) {}

    /**
     * Fechas del rango en formato Y-m-d.
     *
     * @return array<int, string>
     */
    private function dates(): array
    {
        $dates = [];

        for ($d = $this->start->copy(); $d->lte($this->end); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /**
     * Encabezados: datos del empleado, un día por columna y el total de horas.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        $days = array_map(fn (string $date): string => self::DAY_NAMES[Carbon::parse($date)->dayOfWeek].' '.Carbon::parse($date)->format('d/m'), $this->dates());

        return array_merge(['Empleado', 'CI', 'Sucursal', 'Patrón'], $days, ['Horas netas']);
    }

    /**
     * Filas de empleados más las de cobertura.
     *
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [];
        $dates = $this->dates();

        foreach ($this->plan['employees'] as $employee) {
            $minutes = 0;
            $cells = [];

            foreach ($dates as $date) {
                $conflict = $this->plan['conflicts'][$employee->id][$date] ?? null;
                $shift = $this->plan['shifts'][$employee->id][$date] ?? null;

                if ($conflict) {
                    $cells[] = $conflict['label'];
                } elseif ($shift) {
                    $cells[] = $shift['is_day_off'] ? 'Franco' : $shift['name'].($shift['is_override'] ? ' *' : '');
                    $minutes += $shift['net_minutes'] ?? 0;
                } else {
                    $cells[] = '';
                }
            }

            $rows[] = array_merge(
                [$employee->first_name.' '.$employee->last_name, $employee->ci, $employee->branch?->name ?? '—', $this->plan['patterns'][$employee->id] ?? 'Sin patrón'],
                $cells,
                [$minutes > 0 ? Schedule::formatHours($minutes / 60) : '—'],
            );
        }

        $rows[] = [];
        foreach (['working' => 'Trabajan', 'off' => 'De franco', 'away' => 'Vacaciones / licencia'] as $key => $label) {
            $rows[] = array_merge([$label, '', '', ''], array_map(fn (string $date): int => $this->plan['coverage'][$date][$key] ?? 0, $dates), ['']);
        }

        $rows[] = [];
        $rows[] = ['* Turno puntual (cambio manual sobre el ciclo del patrón).'];

        return $rows;
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
