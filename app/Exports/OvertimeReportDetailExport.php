<?php

namespace App\Exports;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta el detalle diario de horas extras y tardanzas para el período dado.
 *
 * Solo incluye días donde el empleado registró horas extras o llegada tarde.
 * Cada fila representa un día de un empleado con los valores desagregados.
 */
class OvertimeReportDetailExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  string|null  $fromDate  Fecha de inicio del período (Y-m-d).
     * @param  string|null  $toDate  Fecha de fin del período (Y-m-d).
     * @param  int|null  $companyId  Filtrar por empresa.
     * @param  int|null  $branchId  Filtrar por sucursal.
     * @param  int|null  $departmentId  Filtrar por departamento (contrato activo).
     * @param  int|null  $employeeId  Filtrar por empleado específico.
     */
    public function __construct(
        protected ?string $fromDate = null,
        protected ?string $toDate = null,
        protected ?int $companyId = null,
        protected ?int $branchId = null,
        protected ?int $departmentId = null,
        protected ?int $employeeId = null,
        protected array $columns = [],
    ) {
        if (empty($this->columns)) {
            $this->columns = static::defaultColumns();
        }
    }

    /** @return array<string, string> */
    public static function availableColumns(): array
    {
        return [
            'employee_name' => 'Empleado',
            'ci' => 'CI',
            'branch_name' => 'Sucursal',
            'date' => 'Fecha',
            'day_name' => 'Día',
            'expected_check_in' => 'Entrada Esperada',
            'check_in' => 'Entrada Real',
            'late_minutes' => 'Tardanza (min)',
            'extra_hours' => 'HE Total (h)',
            'extra_diurnas' => 'HE Diurnas (h)',
            'extra_nocturnas' => 'HE Nocturnas (h)',
            'overtime_reason' => 'Motivo HE',
            'overtime_approved' => 'HE Aprobada',
            'limit_exceeded' => 'Límite Excedido',
            'extraordinary_work' => 'Trabajo Extraordinario',
            'is_holiday' => 'Feriado',
        ];
    }

    /** @return array<string> */
    public static function defaultColumns(): array
    {
        return array_keys(static::availableColumns());
    }

    /**
     * Query base: días con horas extras o tardanza, con relaciones eager-loaded.
     *
     * @return Builder
     */
    public function query()
    {
        return AttendanceDay::query()
            ->with(['employee', 'employee.branch'])
            ->join('employees', 'employees.id', '=', 'attendance_days.employee_id')
            ->select('attendance_days.*')
            ->where(fn ($q) => $q->where('attendance_days.extra_hours', '>', 0)
                ->orWhere('attendance_days.late_minutes', '>', 0)
            )
            ->when($this->fromDate, fn ($q) => $q->whereDate('attendance_days.date', '>=', $this->fromDate))
            ->when($this->toDate, fn ($q) => $q->whereDate('attendance_days.date', '<=', $this->toDate))
            ->when($this->branchId, fn ($q) => $q->where('employees.branch_id', $this->branchId))
            ->when($this->companyId, fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw(1)
                ->from('branches')
                ->whereColumn('branches.id', 'employees.branch_id')
                ->where('branches.company_id', $this->companyId)
            ))
            ->when($this->departmentId, fn ($q) => $q->whereExists(fn ($sub) => $sub->selectRaw(1)
                ->from('contracts')
                ->whereColumn('contracts.employee_id', 'employees.id')
                ->where('contracts.status', 'active')
                ->where('contracts.department_id', $this->departmentId)
            ))
            ->when($this->employeeId, fn ($q) => $q->where('employees.id', $this->employeeId))
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('attendance_days.date');
    }

    /**
     * Encabezados de columna del archivo Excel.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_values(array_intersect_key(static::availableColumns(), array_flip($this->columns)));
    }

    /**
     * Mapea cada día de asistencia a una fila del Excel.
     *
     * @param  AttendanceDay  $day
     * @return array<int, mixed>
     */
    public function map($day): array
    {
        $employee = $day->employee;

        $dayNames = [
            'Monday' => 'Lunes',
            'Tuesday' => 'Martes',
            'Wednesday' => 'Miércoles',
            'Thursday' => 'Jueves',
            'Friday' => 'Viernes',
            'Saturday' => 'Sábado',
            'Sunday' => 'Domingo',
        ];

        $all = [
            'employee_name' => ($employee?->last_name.', '.$employee?->first_name) ?? '—',
            'ci' => $employee?->ci ?? '—',
            'branch_name' => $employee?->branch?->name ?? '—',
            'date' => $day->date?->format('d/m/Y') ?? '',
            'day_name' => $day->date ? ($dayNames[$day->date->format('l')] ?? $day->date->format('l')) : '',
            'expected_check_in' => $day->expected_check_in ? Carbon::parse($day->expected_check_in)->format('H:i') : '',
            'check_in' => $day->check_in_time ? Carbon::parse($day->check_in_time)->format('H:i') : '',
            'late_minutes' => (int) ($day->late_minutes ?? 0),
            'extra_hours' => (float) ($day->extra_hours ?? 0),
            'extra_diurnas' => (float) ($day->extra_hours_diurnas ?? 0),
            'extra_nocturnas' => (float) ($day->extra_hours_nocturnas ?? 0),
            'overtime_reason' => (float) $day->extra_hours > 0 ? AttendanceEvent::overtimeReasonLabel($day->overtime_reason) : '',
            'overtime_approved' => AttendanceDay::formatBoolean((bool) $day->overtime_approved),
            'limit_exceeded' => AttendanceDay::formatBoolean((bool) $day->overtime_limit_exceeded),
            'extraordinary_work' => AttendanceDay::formatBoolean((bool) $day->is_extraordinary_work),
            'is_holiday' => AttendanceDay::formatBoolean((bool) $day->is_holiday),
        ];

        return array_values(array_intersect_key($all, array_flip($this->columns)));
    }

    /**
     * Aplica estilos al encabezado de la hoja de cálculo.
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
