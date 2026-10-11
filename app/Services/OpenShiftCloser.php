<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cierra en bloque jornadas que quedaron sin salida proponiendo como salida la hora de fin del horario esperado
 * de cada día. Es una ayuda para RR.HH.: las marcaciones quedan como manuales (vía `AttendanceEventCorrectionService`)
 * y se omiten los casos donde inventar una hora sería arriesgado.
 */
class OpenShiftCloser
{
    public const SKIP_NOT_OPEN = 'not_open';

    public const SKIP_ON_BREAK = 'on_break';

    public const SKIP_NO_EXPECTED_EXIT = 'no_expected_exit';

    public const SKIP_EXIT_BEFORE_LAST_EVENT = 'exit_before_last_event';

    public const SKIP_PAYROLL_LOCKED = 'payroll_locked';

    public const SKIP_PROTECTED = 'protected';

    /** @var array<int, Collection<int, array{0: string, 1: string}>> Rangos de nómina generada por empleado. */
    private array $payrollRanges = [];

    public function __construct(private readonly AttendanceEventCorrectionService $corrections) {}

    /**
     * Etiquetas de los motivos por los que una jornada se omite.
     *
     * @return array<string, string>
     */
    public static function getSkipLabels(): array
    {
        return [
            self::SKIP_NOT_OPEN => 'no está abierta',
            self::SKIP_ON_BREAK => 'quedó en un descanso (hay que decidir cómo cerrarla)',
            self::SKIP_NO_EXPECTED_EXIT => 'sin hora de salida esperada',
            self::SKIP_EXIT_BEFORE_LAST_EVENT => 'la salida esperada es anterior a su última marcación',
            self::SKIP_PAYROLL_LOCKED => 'dentro de una nómina ya generada',
            self::SKIP_PROTECTED => 'con aprobaciones o ajustes de RR.HH.',
        ];
    }

    /**
     * Evalúa una jornada: devuelve la salida propuesta o el motivo por el que se omite.
     *
     * @return array{exit: Carbon|null, skip: string|null}
     */
    public function evaluate(AttendanceDay $day): array
    {
        $last = $day->events()->orderByDesc('recorded_at')->orderByDesc('id')->first();

        if (! $last || ! $day->date->lt(Carbon::today()) || ! in_array($last->event_type, ['check_in', 'break_start', 'break_end'], true)) {
            return ['exit' => null, 'skip' => self::SKIP_NOT_OPEN];
        }

        if ($last->event_type === 'break_start') {
            return ['exit' => null, 'skip' => self::SKIP_ON_BREAK];
        }

        if (! $day->expected_check_out) {
            return ['exit' => null, 'skip' => self::SKIP_NO_EXPECTED_EXIT];
        }

        if ($this->isPayrollLocked($day)) {
            return ['exit' => null, 'skip' => self::SKIP_PAYROLL_LOCKED];
        }

        if ($day->overtime_approved || $day->tardiness_deduction_approved || $day->manual_adjustment || $day->second_shift_regular) {
            return ['exit' => null, 'skip' => self::SKIP_PROTECTED];
        }

        $exit = $day->date->copy()->startOfDay()->setTimeFromTimeString($day->expected_check_out);

        if ($day->expected_check_in && $day->expected_check_out <= $day->expected_check_in) {
            $exit->addDay();
        }

        if ($exit->lte($last->recorded_at)) {
            return ['exit' => null, 'skip' => self::SKIP_EXIT_BEFORE_LAST_EVENT];
        }

        return ['exit' => $exit, 'skip' => null];
    }

    /**
     * Agrega la salida propuesta y recalcula la jornada. Devuelve el motivo de omisión, o null si se cerró.
     *
     * @throws \InvalidArgumentException Si la secuencia resultante no es válida.
     */
    public function close(AttendanceDay $day): ?string
    {
        ['exit' => $exit, 'skip' => $skip] = $this->evaluate($day);

        if ($skip !== null) {
            return $skip;
        }

        $rows = $day->events()->orderBy('recorded_at')->orderBy('id')->get()
            ->map(fn ($event) => ['id' => $event->id, 'event_type' => $event->event_type, 'recorded_at' => $event->recorded_at])
            ->all();
        $rows[] = ['event_type' => 'check_out', 'recorded_at' => $exit];

        $this->corrections->apply($day, $rows);

        return null;
    }

    /** Indica si la fecha de la jornada cae en un período con nómina generada para el empleado. */
    private function isPayrollLocked(AttendanceDay $day): bool
    {
        $this->payrollRanges[$day->employee_id] ??= Payroll::query()
            ->where('employee_id', $day->employee_id)
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->get(['payroll_periods.start_date', 'payroll_periods.end_date'])
            ->map(fn ($period) => [Carbon::parse($period->start_date)->toDateString(), Carbon::parse($period->end_date)->toDateString()]);

        $date = $day->date->toDateString();

        return $this->payrollRanges[$day->employee_id]->contains(fn (array $range) => $date >= $range[0] && $date <= $range[1]);
    }
}
