<?php

namespace App\Services;

use App\Models\AttendanceDay;
use Illuminate\Support\Facades\DB;

/**
 * Descarta en bloque jornadas sin horario esperado que son marcaciones de prueba (por ejemplo, las que dejó la
 * instalación de un terminal). Elimina la jornada y sus marcaciones; solo actúa sobre jornadas que siguen
 * cumpliendo `AttendanceDay::NO_EXPECTED_SHIFT_SQL` y que no están en una nómina ya generada ni tienen ajustes
 * de RR.HH.
 */
class UnscheduledDayDiscarder
{
    public const SKIP_HAS_SHIFT = 'has_shift';

    public const SKIP_PAYROLL_LOCKED = 'payroll_locked';

    public const SKIP_PROTECTED = 'protected';

    public function __construct(private readonly OpenShiftCloser $closer) {}

    /**
     * Etiquetas de los motivos por los que una jornada se omite.
     *
     * @return array<string, string>
     */
    public static function getSkipLabels(): array
    {
        return [
            self::SKIP_HAS_SHIFT => 'tiene horario esperado o aún no está calculada',
            self::SKIP_PAYROLL_LOCKED => 'dentro de una nómina ya generada',
            self::SKIP_PROTECTED => 'con aprobaciones o ajustes de RR.HH.',
        ];
    }

    /**
     * Motivo por el que la jornada no se puede descartar, o null si se puede.
     */
    public function skipReason(AttendanceDay $day): ?string
    {
        if (! $day->is_calculated || ! $day->check_in_time || $day->expected_check_in !== null) {
            return self::SKIP_HAS_SHIFT;
        }

        if ($this->closer->isPayrollLocked($day)) {
            return self::SKIP_PAYROLL_LOCKED;
        }

        if ($day->overtime_approved || $day->tardiness_deduction_approved || $day->manual_adjustment || $day->second_shift_regular || $day->excluded_from_calculation) {
            return self::SKIP_PROTECTED;
        }

        return null;
    }

    /**
     * Elimina la jornada y sus marcaciones. Devuelve el motivo de omisión, o null si se descartó.
     */
    public function discard(AttendanceDay $day): ?string
    {
        $skip = $this->skipReason($day);

        if ($skip !== null) {
            return $skip;
        }

        DB::transaction(function () use ($day) {
            $day->events()->delete();
            $day->delete();
        });

        return null;
    }
}
