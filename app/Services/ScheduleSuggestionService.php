<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Schedule;
use Carbon\Carbon;

/**
 * Sugiere el horario fijo que mejor coincide con las marcaciones reales de un empleado: compara la
 * entrada y la salida de sus últimas jornadas completas contra los días activos de cada horario.
 * Es solo una ayuda al asignar; nunca asigna nada.
 */
class ScheduleSuggestionService
{
    /** Días hacia atrás que se miran. */
    public const LOOKBACK_DAYS = 30;

    /** Jornadas completas (con entrada y salida) mínimas para sugerir algo. */
    public const MIN_DAYS = 3;

    /** Desvío promedio máximo (minutos por extremo) para considerar que un horario "coincide". */
    public const MAX_AVG_DEVIATION_MINUTES = 60;

    /** Penalización (minutos) por cada jornada trabajada un día que el horario tiene libre. */
    private const DAY_OFF_PENALTY_MINUTES = 240;

    /**
     * @return array{schedule: Schedule, days: int, deviation: int}|null
     */
    public function suggestFor(Employee $employee): ?array
    {
        $worked = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->whereNotNull('check_in_time')
            ->whereNotNull('check_out_time')
            ->get(['date', 'check_in_time', 'check_out_time'])
            ->map(function (AttendanceDay $day): array {
                $in = $this->minutes($day->check_in_time);
                $out = $this->minutes($day->check_out_time);

                return [
                    'weekday' => $day->date->dayOfWeekIso,
                    'in' => $in,
                    'out' => $out <= $in ? $out + 1440 : $out,
                ];
            });

        if ($worked->count() < self::MIN_DAYS) {
            return null;
        }

        $best = null;

        foreach (Schedule::query()->with('activeDays')->get() as $schedule) {
            if ($schedule->activeDays->isEmpty()) {
                continue;
            }

            $days = $schedule->activeDays->keyBy('day_of_week');
            $total = 0.0;

            foreach ($worked as $day) {
                $scheduled = $days->get($day['weekday']);

                if ($scheduled === null) {
                    $total += self::DAY_OFF_PENALTY_MINUTES;

                    continue;
                }

                $start = $this->minutes($scheduled->start_time);
                $end = $this->minutes($scheduled->end_time);
                $end = $end <= $start ? $end + 1440 : $end;

                $total += (abs($day['in'] - $start) + abs($day['out'] - $end)) / 2;
            }

            $deviation = (int) round($total / $worked->count());

            if ($best === null || $deviation < $best['deviation']) {
                $best = ['schedule' => $schedule, 'days' => $worked->count(), 'deviation' => $deviation];
            }
        }

        return $best !== null && $best['deviation'] <= self::MAX_AVG_DEVIATION_MINUTES ? $best : null;
    }

    /** Minutos desde la medianoche de un valor `H:i` o `H:i:s`. */
    private function minutes(?string $time): int
    {
        preg_match('/(\d{1,2}):(\d{2})/', (string) $time, $m);

        return ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
    }
}
