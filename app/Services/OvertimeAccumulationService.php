<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;

/**
 * Detecta a quienes superan el tope semanal o mensual de horas extra configurado en «Horas extra» y arma, por
 * destinatario, la lista que se avisa: RR.HH. ve a todos y cada jefe solo a su equipo.
 */
class OvertimeAccumulationService
{
    public const WEEK = 'week';

    public const MONTH = 'month';

    /**
     * Empleados que superan algún tope, con sus horas.
     *
     * @return array<int, array{employee_id: int, name: string, boss_employee_id: ?int, week: float, month: float, exceeded: array<int, string>}>
     */
    public function exceeding(?CarbonInterface $today = null): array
    {
        $settings = app(GeneralSettings::class);
        $weekLimit = (int) $settings->attendance_overtime_weekly_alert_hours;
        $monthLimit = (int) $settings->attendance_overtime_monthly_alert_hours;

        if ($weekLimit <= 0 && $monthLimit <= 0) {
            return [];
        }

        $today ??= today();
        $week = $this->sumByEmployee($today->copy()->startOfWeek(), $today);
        $month = $this->sumByEmployee($today->copy()->startOfMonth(), $today);

        $ids = array_unique([...array_keys($week), ...array_keys($month)]);
        $employees = Employee::query()->active()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'reports_to_id'])->keyBy('id');
        $rows = [];

        foreach ($employees as $id => $employee) {
            $weekHours = (float) ($week[$id] ?? 0);
            $monthHours = (float) ($month[$id] ?? 0);
            $exceeded = [];
            if ($weekLimit > 0 && $weekHours > $weekLimit) {
                $exceeded[] = self::WEEK;
            }
            if ($monthLimit > 0 && $monthHours > $monthLimit) {
                $exceeded[] = self::MONTH;
            }
            if ($exceeded === []) {
                continue;
            }

            $rows[] = [
                'employee_id' => (int) $id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'boss_employee_id' => $employee->reports_to_id ? (int) $employee->reports_to_id : null,
                'week' => round($weekHours, 2),
                'month' => round($monthHours, 2),
                'exceeded' => $exceeded,
            ];
        }

        return $rows;
    }

    /**
     * Filas que le corresponden a un usuario: todas si es de RR.HH., las de su equipo si es jefe.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function rowsFor(User $user, array $rows): array
    {
        if ($user->can(OvertimeRequestService::HR_APPROVE)) {
            return $rows;
        }

        $team = app(TeamScopeService::class)->employeeIds($user);

        return array_values(array_filter($rows, fn (array $row): bool => in_array($row['employee_id'], $team, true)));
    }

    /**
     * Horas extra por empleado entre dos fechas (jornadas con extra, sin descartar ni aceptadas como turno normal).
     *
     * @return array<int, float>
     */
    private function sumByEmployee(CarbonInterface $from, CarbonInterface $to): array
    {
        return AttendanceDay::query()
            ->where('extra_hours', '>', 0)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('overtime_dismissed_at')
            ->where(fn ($q) => $q->whereNull('second_shift_regular')->orWhere('second_shift_regular', false))
            ->selectRaw('employee_id, SUM(extra_hours) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->map(fn ($v): float => (float) $v)
            ->all();
    }
}
