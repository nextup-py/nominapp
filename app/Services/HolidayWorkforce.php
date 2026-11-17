<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Quién trabaja (o trabajó) un día: para fechas pasadas y de hoy, quienes tienen marcación; para fechas futuras,
 * quienes tienen turno según su horario, rotación o turno puntual (`AttendanceCalculator::resolveShiftDataFor()`).
 */
class HolidayWorkforce
{
    /**
     * Empleados de un día, ordenados por sucursal y nombre.
     *
     * @return array{mode: 'worked'|'scheduled', employees: Collection<int, array{name: string, branch: string, detail: string}>}
     */
    public static function forDate(Carbon $date): array
    {
        if ($date->lte(today())) {
            $days = AttendanceDay::query()
                ->whereDate('date', $date)
                ->whereNotNull('check_in_time')
                ->with('employee.branch')
                ->get();

            return ['mode' => 'worked', 'employees' => $days
                ->filter(fn (AttendanceDay $day): bool => $day->employee !== null)
                ->map(fn (AttendanceDay $day): array => [
                    'name' => $day->employee->full_name,
                    'branch' => $day->employee->branch?->name ?? 'Sin sucursal',
                    'detail' => number_format((float) $day->total_hours, 1, ',', '.').' h',
                ])
                ->sortBy(fn (array $row): string => $row['branch'].'|'.$row['name'])
                ->values()];
        }

        $employees = Employee::query()->where('status', 'active')->whereHas('activeContract')->with('branch')->get();

        return ['mode' => 'scheduled', 'employees' => $employees
            ->map(function (Employee $employee) use ($date): ?array {
                $shift = AttendanceCalculator::resolveShiftDataFor($employee, $date);

                return $shift['check_in'] === null ? null : [
                    'name' => $employee->full_name,
                    'branch' => $employee->branch?->name ?? 'Sin sucursal',
                    'detail' => substr((string) $shift['check_in'], 0, 5).' – '.substr((string) $shift['check_out'], 0, 5),
                ];
            })
            ->filter()
            ->sortBy(fn (array $row): string => $row['branch'].'|'.$row['name'])
            ->values()];
    }
}
