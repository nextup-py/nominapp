<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * Lista, solo para lectura, a quienes tienen atrasos pendientes muy largos (por defecto más de 60 min) con su horario
 * vigente, la hora de entrada esperada guardada y la hora a la que realmente entran: un atraso de horas casi siempre es un
 * horario mal asignado o cargado en otro turno, no una tardanza real.
 */
class ReviewLongLates extends Command
{
    protected $signature = 'attendance:review-long-lates
                            {--min=60 : Minutos de atraso a partir de los cuales se lista}
                            {--days= : Ventana en días hacia atrás (por defecto, la de «Requieren atención»)}';

    protected $description = 'Lista las personas con atrasos pendientes muy largos junto a su horario, para detectar horarios mal asignados';

    public function handle(): int
    {
        $from = $this->option('days')
            ? now()->subDays((int) $this->option('days'))->startOfDay()
            : AttendanceDay::attentionWindowStart();

        $days = AttendanceDay::query()
            ->where('attendance_days.date', '>=', $from)
            ->whereRaw(AttendanceDay::PENDING_TARDINESS_SQL)
            ->where('attendance_days.late_minutes', '>', (int) $this->option('min'))
            ->get(['id', 'employee_id', 'date', 'expected_check_in', 'check_in_time', 'late_minutes'])
            ->groupBy('employee_id');

        $employees = Employee::query()->with('branch')->whereIn('id', $days->keys())->get()->keyBy('id');

        $this->line("Personas con atrasos de más de {$this->option('min')} min: {$days->count()}");

        foreach ($days->sortByDesc(fn ($group) => $group->count()) as $employeeId => $group) {
            $employee = $employees[$employeeId] ?? null;
            $schedule = $employee?->getScheduleForDate($group->last()->date);
            $expected = $group->pluck('expected_check_in')->filter()->map(fn ($time) => substr((string) $time, 0, 5))->unique()->implode(', ');
            $actual = $group->pluck('check_in_time')->filter()->map(fn ($time) => $time instanceof \DateTimeInterface ? $time->format('H:i') : substr((string) $time, 11, 5))->sort()->values();

            $this->line(sprintf(
                '#%d %s | %s | %d día(s), %d min en promedio | esperado: %s | entra: %s a %s | horario fijo: %s',
                $employeeId,
                trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')),
                $employee?->branch?->name ?? 'sin sucursal',
                $group->count(),
                (int) round($group->avg('late_minutes')),
                $expected ?: '-',
                $actual->first() ?? '-',
                $actual->last() ?? '-',
                $schedule?->name ?? 'ninguno (rotación o turno puntual)',
            ));
        }

        return self::SUCCESS;
    }
}
