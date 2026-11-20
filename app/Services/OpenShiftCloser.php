<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
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

    public const SKIP_ENTRY_FAR_FROM_SHIFT = 'entry_far_from_shift';

    public const SKIP_NOT_APPLICABLE = 'not_applicable';

    public const SKIP_NOT_NEAR_EXIT = 'not_near_exit';

    /** Estrategia: el inicio de descanso marcado cerca de la hora de salida se toma como la salida (era el botón equivocado). */
    public const STRATEGY_BREAK_AS_EXIT = 'break_as_exit';

    /** Estrategia: se cierra en la hora de la última marcación real (un fin de descanso posterior a la salida del horario). */
    public const STRATEGY_LAST_MARK = 'last_mark';

    /** Minutos de margen alrededor de la hora de salida del horario para las estrategias que usan una marcación real. */
    public const REAL_MARK_WINDOW_MINUTES = 60;

    /** Minutos de diferencia entre la primera entrada y el inicio del horario a partir de los cuales no se propone una salida. */
    public const MAX_ENTRY_DEVIATION_MINUTES = 180;

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
            self::SKIP_ENTRY_FAR_FROM_SHIFT => 'la entrada está a más de 3 h del horario (puede ser la salida del turno anterior)',
            self::SKIP_NOT_APPLICABLE => 'su última marcación no corresponde a esta regla',
            self::SKIP_NOT_NEAR_EXIT => 'su última marcación está a más de 1 h de la hora de salida del horario',
        ];
    }

    /**
     * Evalúa una jornada: devuelve la salida propuesta o el motivo por el que se omite.
     *
     * @return array{exit: Carbon|null, skip: string|null}
     */
    public function evaluate(AttendanceDay $day): array
    {
        $events = $day->events()->orderBy('recorded_at')->orderBy('id')->get();
        $last = $events->last();

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

        if ($day->hasProtectedDecisions()) {
            return ['exit' => null, 'skip' => self::SKIP_PROTECTED];
        }

        $exit = $day->date->copy()->startOfDay()->setTimeFromTimeString($day->expected_check_out);

        if ($day->expected_check_in && $day->expected_check_out <= $day->expected_check_in) {
            $exit->addDay();
        }

        if ($exit->lte($last->recorded_at)) {
            return ['exit' => null, 'skip' => self::SKIP_EXIT_BEFORE_LAST_EVENT];
        }

        if ($this->isEntryFarFromShift($day, $events->first())) {
            return ['exit' => null, 'skip' => self::SKIP_ENTRY_FAR_FROM_SHIFT];
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

    /**
     * Evalúa una jornada con una estrategia que usa una marcación real en vez de la hora del horario: o bien toma el
     * inicio de descanso marcado cerca de la hora de salida como la salida (`STRATEGY_BREAK_AS_EXIT`), o bien cierra
     * en el fin de descanso posterior a la salida del horario (`STRATEGY_LAST_MARK`). Las dos exigen que la marcación
     * esté a `REAL_MARK_WINDOW_MINUTES` o menos de la salida esperada y respetan las mismas protecciones que `evaluate()`.
     *
     * @return array{event: AttendanceEvent|null, exit: Carbon|null, skip: string|null}
     */
    public function evaluateRealMark(AttendanceDay $day, string $strategy): array
    {
        $events = $day->events()->orderBy('recorded_at')->orderBy('id')->get();
        $last = $events->last();

        if (! $last || ! $day->date->lt(Carbon::today()) || ! in_array($last->event_type, ['check_in', 'break_start', 'break_end'], true)) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_NOT_OPEN];
        }

        $expectedType = $strategy === self::STRATEGY_BREAK_AS_EXIT ? 'break_start' : 'break_end';

        if ($last->event_type !== $expectedType) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_NOT_APPLICABLE];
        }

        if (! $day->expected_check_out) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_NO_EXPECTED_EXIT];
        }

        if ($this->isPayrollLocked($day)) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_PAYROLL_LOCKED];
        }

        if ($day->hasProtectedDecisions()) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_PROTECTED];
        }

        if ($this->isEntryFarFromShift($day, $events->first())) {
            return ['event' => null, 'exit' => null, 'skip' => self::SKIP_ENTRY_FAR_FROM_SHIFT];
        }

        $expectedExit = $day->date->copy()->startOfDay()->setTimeFromTimeString($day->expected_check_out);

        if ($day->expected_check_in && $day->expected_check_out <= $day->expected_check_in) {
            $expectedExit->addDay();
        }

        $minutesAfterExit = $expectedExit->diffInMinutes($last->recorded_at, false);

        $near = $strategy === self::STRATEGY_BREAK_AS_EXIT
            ? abs($minutesAfterExit) <= self::REAL_MARK_WINDOW_MINUTES
            : $minutesAfterExit > 0 && $minutesAfterExit <= self::REAL_MARK_WINDOW_MINUTES;

        if (! $near) {
            return ['event' => null, 'exit' => null, 'skip' => $strategy === self::STRATEGY_LAST_MARK && $minutesAfterExit <= 0 ? self::SKIP_NOT_APPLICABLE : self::SKIP_NOT_NEAR_EXIT];
        }

        return ['event' => $last, 'exit' => $last->recorded_at->copy(), 'skip' => null];
    }

    /**
     * Cierra la jornada con una estrategia de marcación real (ver `evaluateRealMark()`) y la recalcula. Devuelve el
     * motivo de omisión, o null si se cerró.
     *
     * @throws \InvalidArgumentException Si la secuencia resultante no es válida.
     */
    public function closeWithRealMark(AttendanceDay $day, string $strategy): ?string
    {
        ['event' => $event, 'exit' => $exit, 'skip' => $skip] = $this->evaluateRealMark($day, $strategy);

        if ($skip !== null) {
            return $skip;
        }

        $rows = $day->events()->orderBy('recorded_at')->orderBy('id')->get()
            ->map(fn (AttendanceEvent $existing) => [
                'id' => $existing->id,
                'event_type' => $strategy === self::STRATEGY_BREAK_AS_EXIT && $existing->is($event) ? 'check_out' : $existing->event_type,
                'recorded_at' => $existing->recorded_at,
            ])
            ->all();

        if ($strategy === self::STRATEGY_LAST_MARK) {
            $rows[] = ['event_type' => 'check_out', 'recorded_at' => $this->exitAfter($exit)];
        }

        $this->corrections->apply($day, $rows);

        return null;
    }

    /**
     * Hora de la salida agregada tras la última marcación: el servicio de correcciones trabaja al minuto, así que
     * con segundos la salida se lleva al minuto siguiente para no quedar antes de esa marcación.
     */
    public function exitAfter(Carbon $lastMark): Carbon
    {
        $exit = $lastMark->copy()->seconds(0);

        return $lastMark->second > 0 ? $exit->addMinute() : $exit;
    }

    /**
     * Cierra la jornada con la mejor regla disponible: la hora del horario; si quedó en un descanso, el inicio de
     * descanso marcado cerca de la salida; si su última marcación es posterior a la salida del horario, esa marcación.
     * Devuelve la regla usada (`schedule`, `break_as_exit` o `last_mark`) o el motivo por el que se omitió.
     *
     * @return array{closed_with: string|null, skip: string|null}
     *
     * @throws \InvalidArgumentException Si la secuencia resultante no es válida.
     */
    public function closeBest(AttendanceDay $day): array
    {
        $skip = $this->close($day);

        if ($skip === null) {
            return ['closed_with' => 'schedule', 'skip' => null];
        }

        $strategy = match ($skip) {
            self::SKIP_ON_BREAK => self::STRATEGY_BREAK_AS_EXIT,
            self::SKIP_EXIT_BEFORE_LAST_EVENT => self::STRATEGY_LAST_MARK,
            default => null,
        };

        if ($strategy !== null && $this->closeWithRealMark($day, $strategy) === null) {
            return ['closed_with' => $strategy, 'skip' => null];
        }

        return ['closed_with' => null, 'skip' => $skip];
    }

    /**
     * Indica si la primera marcación del día está demasiado lejos del inicio del horario esperado: suele ser la
     * salida del turno anterior marcada como entrada, y cerrarla con la salida del horario daría una jornada de
     * casi 24 horas. Se compara también contra el inicio del día anterior y del siguiente (turnos que cruzan medianoche).
     */
    private function isEntryFarFromShift(AttendanceDay $day, ?AttendanceEvent $first): bool
    {
        if (! $first || ! $day->expected_check_in) {
            return false;
        }

        $start = $day->date->copy()->startOfDay()->setTimeFromTimeString($day->expected_check_in);
        $closest = collect([-1, 0, 1])
            ->map(fn (int $shift) => abs($first->recorded_at->diffInMinutes($start->copy()->addDays($shift), false)))
            ->min();

        return $closest > self::MAX_ENTRY_DEVIATION_MINUTES;
    }

    /** Indica si la fecha de la jornada cae en un período con nómina generada para el empleado. */
    public function isPayrollLocked(AttendanceDay $day): bool
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
