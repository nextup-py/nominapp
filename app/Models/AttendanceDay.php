<?php

namespace App\Models;

use App\Services\AttendanceCalculator;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class AttendanceDay extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /** @var array<int, string> Campos auditados en el historial de cambios. */
    protected array $auditInclude = [
        'overtime_approved',
        'second_shift_regular',
        'tardiness_deduction_approved',
        'notes',
        'manual_adjustment',
        'extra_hours_diurnas',
        'extra_hours_nocturnas',
    ];

    protected $fillable = [
        'employee_id',
        'date',
        'status',
        'total_hours',
        'net_hours',
        'expected_hours',
        'expected_check_in',
        'expected_check_out',
        'expected_break_minutes',
        'late_minutes',
        'early_leave_minutes',
        'extra_hours',
        'extra_hours_diurnas',
        'extra_hours_nocturnas',
        'break_minutes',
        'check_in_time',
        'check_out_time',
        'anomaly_flag',
        'notes',
        'is_weekend',
        'is_extraordinary_work',
        'is_holiday',
        'manual_adjustment',
        'overtime_approved',
        'second_shift_regular',
        'overtime_limit_exceeded',
        'tardiness_deduction_approved',
        'on_vacation',
        'justified_absence',
        'is_calculated',
        'calculated_at',
    ];

    protected $casts = [
        'date' => 'date',
        'total_hours' => 'decimal:2',
        'net_hours' => 'decimal:2',
        'expected_hours' => 'decimal:2',
        'expected_break_minutes' => 'integer',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'extra_hours' => 'decimal:2',
        'extra_hours_diurnas' => 'decimal:2',
        'extra_hours_nocturnas' => 'decimal:2',
        'break_minutes' => 'integer',
        'anomaly_flag' => 'boolean',
        'is_weekend' => 'boolean',
        'is_extraordinary_work' => 'boolean',
        'is_holiday' => 'boolean',
        'manual_adjustment' => 'boolean',
        'overtime_approved' => 'boolean',
        'second_shift_regular' => 'boolean',
        'overtime_limit_exceeded' => 'boolean',
        'tardiness_deduction_approved' => 'boolean',
        'on_vacation' => 'boolean',
        'justified_absence' => 'boolean',
        'is_calculated' => 'boolean',
        'calculated_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    /**
     * Resuelve a qué jornada (AttendanceDay) debe asociarse un evento entrante,
     * soportando turnos que cruzan medianoche (ej: entrada 17:00 → salida 01:00
     * del día siguiente).
     *
     * Primero intenta la jornada del día calendario de `$recordedAt`. Si la
     * transición pedida no es válida ahí (típicamente porque esa jornada no
     * tiene marcaciones todavía), revisa la jornada del día calendario
     * anterior: si sigue abierta (su último evento no es `check_out`) y la
     * transición pedida sí es válida para ella, el evento continúa esa
     * jornada en lugar de abrir una nueva. Nunca mira más de un día hacia
     * atrás — una jornada abierta de hace 2+ días requiere revisión manual
     * (ver `attendance:check-missing`), no se reabre automáticamente.
     *
     * "Inicio de descanso" además solo se considera válido si el turno/horario
     * efectivo del empleado para el día en cuestión tiene un descanso
     * configurado (ver `AttendanceCalculator::hasScheduledBreak()`) — "Fin de
     * descanso" nunca se filtra así: si el empleado ya está en pausa, siempre
     * puede cerrarla.
     *
     * @return array{day: ?self, last: ?AttendanceEvent, allowed: array<int, string>} `day` es null cuando debe crearse una jornada nueva para hoy (ninguna de las dos jornadas permite la transición pedida, o la de hoy sí la permite pero aún no existe). `allowed` ya viene filtrado por horario/descanso — usarlo para la validación final en el caller, no recalcular con AttendanceEvent::allowedNextEventTypes() directamente.
     */
    public static function resolveForEvent(Employee $employee, Carbon $recordedAt, string $requestedEventType, bool $lockForUpdate = false, bool $enforceReentryGap = true): array
    {
        $resolveLast = function (?self $day) use ($lockForUpdate) {
            if (! $day) {
                return null;
            }

            $query = AttendanceEvent::where('attendance_day_id', $day->id)->latest('recorded_at');

            return $lockForUpdate ? $query->lockForUpdate()->first() : $query->first();
        };

        $today = static::where('employee_id', $employee->id)->where('date', $recordedAt->toDateString())->first();
        $todayLast = $resolveLast($today);
        $todayAllowed = static::filterAllowedByBreakSchedule(
            AttendanceEvent::allowedNextEventTypes($todayLast?->event_type),
            $employee,
            $recordedAt
        );
        if ($enforceReentryGap) {
            $todayAllowed = static::filterAllowedByReentryGap($todayAllowed, $todayLast, $recordedAt);
        }

        if (in_array($requestedEventType, $todayAllowed, true)) {
            return ['day' => $today, 'last' => $todayLast, 'allowed' => $todayAllowed];
        }

        $yesterdayDate = $recordedAt->copy()->subDay();
        $yesterday = static::where('employee_id', $employee->id)
            ->where('date', $yesterdayDate->toDateString())
            ->first();
        $yesterdayLast = $resolveLast($yesterday);
        $yesterdayAllowed = $yesterdayLast
            ? static::filterAllowedByBreakSchedule(AttendanceEvent::allowedNextEventTypes($yesterdayLast->event_type), $employee, $yesterdayDate)
            : [];

        if ($yesterdayLast
            && $yesterdayLast->event_type !== 'check_out'
            && in_array($requestedEventType, $yesterdayAllowed, true)
        ) {
            return ['day' => $yesterday, 'last' => $yesterdayLast, 'allowed' => $yesterdayAllowed];
        }

        return ['day' => $today, 'last' => $todayLast, 'allowed' => $todayAllowed];
    }

    /** Días hacia atrás que se revisan al buscar una jornada vieja que quedó abierta. */
    public const STALE_OPEN_LOOKBACK_DAYS = 7;

    /** Último evento que deja una jornada abierta (sin `check_out`). */
    private const OPEN_LAST_EVENTS = ['check_in', 'break_start', 'break_end'];

    /**
     * Subconsulta SQL (correlacionada con `attendance_days`) con el tipo del último evento del día.
     * Es la única definición de "último evento": la usan los scopes, el listado de Asistencias y
     * sus contadores, para que coincidan siempre.
     */
    public static function lastEventTypeSql(): string
    {
        return '(select e.event_type from attendance_events e where e.attendance_day_id = attendance_days.id order by e.recorded_at desc, e.id desc limit 1)';
    }

    /** Condición SQL de "jornada abierta": el último evento no es una salida (placeholders para los 3 tipos). */
    private static function openLastEventSql(): string
    {
        return self::lastEventTypeSql().' in (?, ?, ?)';
    }

    /**
     * Condición SQL de "sin salida registrada": jornada anterior a hoy cuya última marcación deja
     * la jornada abierta. Los bindings son `[hoy, ...tipos abiertos]` (ver `missingCheckOutBindings()`).
     */
    public static function missingCheckOutSql(): string
    {
        return 'attendance_days.date < ? and '.self::openLastEventSql();
    }

    /**
     * Bindings de `missingCheckOutSql()`.
     *
     * @return array<int, string>
     */
    public static function missingCheckOutBindings(): array
    {
        return [Carbon::today()->toDateString(), ...self::OPEN_LAST_EVENTS];
    }

    /**
     * Jornadas anteriores a hoy que tienen marcaciones pero cuyo último evento no es una salida:
     * el empleado entró y nunca salió. Se cuentan desde el día siguiente al turno, así un turno
     * nocturno que arrancó ayer no se confunde con una jornada de hoy en curso.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMissingCheckOut(Builder $query): Builder
    {
        return $query->whereRaw(self::missingCheckOutSql(), self::missingCheckOutBindings());
    }

    /**
     * Días hacia atrás que mira "Requieren atención". Sin límite, el primer día el listado mostraría
     * todo el historial de tardanzas y extras sin aprobar y taparía lo urgente; lo más viejo sigue en
     * "Todos" e "Incompletos".
     */
    public const ATTENTION_WINDOW_DAYS = 30;

    /** Condición SQL de tardanza con descuento todavía sin decidir por RR.HH. */
    public const PENDING_TARDINESS_SQL = 'attendance_days.late_minutes > 0 and coalesce(attendance_days.tardiness_deduction_approved, 0) = 0';

    /** Condición SQL de horas extra sin aprobar (un doble turno aceptado como jornada normal no cuenta). */
    public const PENDING_OVERTIME_SQL = 'attendance_days.extra_hours > 0 and coalesce(attendance_days.overtime_approved, 0) = 0 and coalesce(attendance_days.second_shift_regular, 0) = 0';

    /**
     * Jornadas con tardanza cuyo descuento RR.HH. todavía no aprobó.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingTardiness(Builder $query): Builder
    {
        return $query->whereRaw(self::PENDING_TARDINESS_SQL);
    }

    /**
     * Jornadas con horas extra todavía sin aprobar.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingOvertime(Builder $query): Builder
    {
        return $query->whereRaw(self::PENDING_OVERTIME_SQL);
    }

    /**
     * Jornadas que requieren una decisión o corrección de RR.HH.: sin salida, tardanza por aprobar
     * o horas extra por aprobar, dentro de los últimos `ATTENTION_WINDOW_DAYS` días. Es la definición de la
     * pestaña "Requieren atención" de Asistencias.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query
            ->where('attendance_days.date', '>=', self::attentionWindowStart())
            ->where(fn (Builder $q) => $q
                ->whereRaw(self::missingCheckOutSql(), self::missingCheckOutBindings())
                ->orWhereRaw(self::PENDING_TARDINESS_SQL)
                ->orWhereRaw(self::PENDING_OVERTIME_SQL));
    }

    /** Primer día (inclusive, `Y-m-d`) que considera "Requieren atención". */
    public static function attentionWindowStart(): string
    {
        return Carbon::today()->subDays(self::ATTENTION_WINDOW_DAYS)->toDateString();
    }

    /**
     * Motivos por los que la jornada requiere atención, para mostrarlos como badges en el listado.
     * Usa `last_event_type` si el listado ya lo trajo (evita una consulta por fila).
     *
     * @return array<int, string>
     */
    public function attentionReasons(): array
    {
        $reasons = [];

        $lastEvent = array_key_exists('last_event_type', $this->attributes)
            ? $this->attributes['last_event_type']
            : $this->events()->latest('recorded_at')->latest('id')->value('event_type');

        if ($this->date->lt(Carbon::today()) && in_array($lastEvent, self::OPEN_LAST_EVENTS, true)) {
            $reasons[] = 'Sin salida';
        }

        if ((int) $this->late_minutes > 0 && ! $this->tardiness_deduction_approved) {
            $reasons[] = 'Tardanza por aprobar';
        }

        if ((float) $this->extra_hours > 0 && ! $this->overtime_approved && ! $this->second_shift_regular) {
            $reasons[] = 'Extras por aprobar';
        }

        return $reasons;
    }

    /**
     * La jornada abierta más reciente del empleado que ya es más vieja que "ayer" respecto de
     * `$recordedAt` (la de ayer la resuelve `resolveForEvent()` por turno nocturno). Sirve para
     * avisar a RR.HH., en el conflicto de una salida huérfana, que quedó una entrada sin cerrar.
     */
    public static function staleOpenDayFor(Employee $employee, Carbon $recordedAt): ?self
    {
        return static::query()
            ->where('employee_id', $employee->id)
            ->where('date', '<', $recordedAt->copy()->subDay()->toDateString())
            ->where('date', '>=', $recordedAt->copy()->subDays(self::STALE_OPEN_LOOKBACK_DAYS)->toDateString())
            ->whereRaw(self::openLastEventSql(), self::OPEN_LAST_EVENTS)
            ->orderByDesc('date')
            ->first();
    }

    /**
     * Datos de la jornada vieja abierta para guardar en el `metadata` de un conflicto, y el texto
     * que se agrega al mensaje. Vacío si el empleado no tiene ninguna.
     *
     * @return array{metadata: array<string, mixed>, message: string}
     */
    public static function staleOpenDayConflictInfo(Employee $employee, Carbon $recordedAt): array
    {
        $day = static::staleOpenDayFor($employee, $recordedAt);

        if (! $day) {
            return ['metadata' => [], 'message' => ''];
        }

        $date = $day->date->format('d/m/Y');

        return [
            'metadata' => [
                'open_day_id' => $day->id,
                'open_day_date' => $day->date->toDateString(),
            ],
            'message' => " El empleado tiene una jornada abierta sin salida del {$date}: corregirla desde Asistencias antes de resolver este conflicto.",
        ];
    }

    /**
     * Quita 'check_in' mientras no pasó el mínimo configurado desde la última salida: una nueva
     * entrada el mismo día (segundo turno) es válida, pero no a los pocos segundos de marcar la
     * salida (doble toque, rostro reconocido dos veces). Con el mínimo en 0 no filtra nada.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private static function filterAllowedByReentryGap(array $allowed, ?AttendanceEvent $last, Carbon $at): array
    {
        if ($last?->event_type !== 'check_out' || ! in_array('check_in', $allowed, true)) {
            return $allowed;
        }

        $minMinutes = (int) app(GeneralSettings::class)->attendance_min_reentry_minutes;

        if ($minMinutes > 0 && $at->lt($last->recorded_at->copy()->addMinutes($minMinutes))) {
            return array_values(array_diff($allowed, ['check_in']));
        }

        return $allowed;
    }

    /**
     * Quita 'break_start' del listado de transiciones permitidas si el turno del
     * empleado para esa fecha no tiene descanso configurado. 'break_end' nunca
     * se filtra — si ya empezó la pausa, siempre debe poder cerrarla.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private static function filterAllowedByBreakSchedule(array $allowed, Employee $employee, Carbon $date): array
    {
        if (! in_array('break_start', $allowed, true)) {
            return $allowed;
        }

        if (AttendanceCalculator::hasScheduledBreak($employee, $date)) {
            return $allowed;
        }

        return array_values(array_diff($allowed, ['break_start']));
    }

    /**
     * Estado informativo (sin persistir nada) usado por `identify()` para mostrar
     * al empleado qué puede marcar ahora, considerando una posible jornada
     * nocturna del día anterior que sigue abierta. `allowed` es la unión de lo
     * que permitiría continuar hoy y lo que permitiría cerrar/continuar esa
     * jornada de ayer — refleja exactamente lo que `resolveForEvent()`
     * aceptaría, sin decidir todavía a qué jornada se asociará.
     *
     * @return array{last: ?AttendanceEvent, allowed: array<int, string>}
     */
    public static function currentStateFor(Employee $employee, Carbon $recordedAt): array
    {
        $today = static::where('employee_id', $employee->id)->where('date', $recordedAt->toDateString())->first();
        $todayLast = $today ? AttendanceEvent::where('attendance_day_id', $today->id)->latest('recorded_at')->first() : null;

        $yesterdayDate = $recordedAt->copy()->subDay();
        $yesterday = static::where('employee_id', $employee->id)
            ->where('date', $yesterdayDate->toDateString())
            ->first();
        $yesterdayLast = $yesterday ? AttendanceEvent::where('attendance_day_id', $yesterday->id)->latest('recorded_at')->first() : null;

        $overnightOpen = $yesterdayLast && $yesterdayLast->event_type !== 'check_out';

        $allowed = static::filterAllowedByReentryGap(
            static::filterAllowedByBreakSchedule(
                AttendanceEvent::allowedNextEventTypes($todayLast?->event_type),
                $employee,
                $recordedAt
            ),
            $todayLast,
            $recordedAt
        );
        if ($overnightOpen) {
            $allowed = array_values(array_unique(array_merge(
                $allowed,
                static::filterAllowedByBreakSchedule(AttendanceEvent::allowedNextEventTypes($yesterdayLast->event_type), $employee, $yesterdayDate)
            )));
        }

        return [
            'last' => $todayLast ?? ($overnightOpen ? $yesterdayLast : null),
            'allowed' => $allowed,
        ];
    }

    /**
     * Indica si el día tiene más de una entrada (un segundo turno después de una salida).
     */
    public function hasMultipleShifts(): bool
    {
        return $this->events()->where('event_type', 'check_in')->count() > 1;
    }

    /**
     * Relación con el modelo Absence, un día de asistencia puede tener una ausencia
     */
    public function absence()
    {
        return $this->hasOne(Absence::class);
    }

    public function getDateFormattedAttribute(): string
    {
        return Carbon::parse($this->date)->format('d/m/Y');
    }

    /**
     * Obtiene una descripción completa del día de asistencia
     */
    public function getDescriptionAttribute(): string
    {
        return "{$this->date_formatted} - {$this->employee->full_name}";
    }

    public function getStatusInSpanishAttribute(): string
    {
        return self::getStatusLabel($this->status);
    }

    /**
     * Obtiene el label traducido del estado
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'present' => 'Presente',
            'absent' => 'Ausente',
            'on_leave' => 'De permiso',
            'holiday' => 'Feriado',
            'weekend' => 'Fin de semana',
            default => 'Desconocido',
        };
    }

    /**
     * Obtiene el color del badge según el estado
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'present' => 'success',
            'absent' => 'danger',
            'on_leave' => 'warning',
            'holiday' => 'info',
            'weekend' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Obtiene el icono según el estado
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'present' => 'heroicon-o-check-circle',
            'absent' => 'heroicon-o-x-circle',
            'on_leave' => 'heroicon-o-document-text',
            'holiday' => 'heroicon-o-gift',
            'weekend' => 'heroicon-o-calendar',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    /**
     * Obtiene todas las opciones de estado para selects
     */
    public static function getStatusOptions(): array
    {
        return [
            'present' => 'Presente',
            'absent' => 'Ausente',
            'on_leave' => 'De permiso',
            'holiday' => 'Feriado',
            'weekend' => 'Fin de semana',
        ];
    }

    /**
     * Formatea un valor booleano para mostrar Sí/No
     */
    public static function formatBoolean(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }

    /**
     * Obtiene el color para un badge booleano
     */
    public static function getBooleanColor(bool $value, string $trueColor = 'success', string $falseColor = 'gray'): string
    {
        return $value ? $trueColor : $falseColor;
    }

    /**
     * Obtiene el mensaje de status después de calcular/recalcular
     */
    public function getStatusMessage(bool $wasCalculated): string
    {
        $action = $wasCalculated ? 'recalculado' : 'calculado';

        return match ($this->status) {
            'present' => "Empleado presente — Cálculos {$action}s",
            'absent' => 'Empleado ausente',
            'on_leave' => 'Empleado con permiso o vacaciones',
            'holiday' => 'Día feriado',
            'weekend' => 'Fin de semana',
            default => "Cálculo {$action}",
        };
    }

    /**
     * Obtiene el color para la columna de entrada según si llegó tarde o no marcó
     */
    public function getCheckInStatusColor(): string
    {
        if (! $this->check_in_time) {
            return 'gray';
        }

        return $this->late_minutes > 0 ? 'danger' : 'success';
    }

    /**
     * Obtiene el tooltip para la columna de entrada
     */
    public function getCheckInTooltip(): string
    {
        if (! $this->check_in_time) {
            return 'Sin marcación de entrada';
        }

        return $this->late_minutes > 0
            ? "Tarde: {$this->late_minutes} min"
            : 'A tiempo';
    }

    /**
     * Obtiene el color para la columna de salida según si salió antes o no marcó
     */
    public function getCheckOutStatusColor(): string
    {
        if (! $this->check_out_time) {
            return 'gray';
        }

        return $this->early_leave_minutes > 0 ? 'warning' : 'success';
    }

    /**
     * Obtiene el tooltip para la columna de salida
     */
    public function getCheckOutTooltip(): string
    {
        if (! $this->check_out_time) {
            return 'Sin marcación de salida';
        }

        return $this->early_leave_minutes > 0
            ? "Salida anticipada: {$this->early_leave_minutes} min"
            : 'A tiempo';
    }

    /**
     * Obtiene el tooltip para el estado de cálculo
     */
    public function getCalculationTooltip(): string
    {
        return $this->calculated_at
            ? 'Calculado: '.$this->calculated_at->format('d/m/Y H:i')
            : 'Aún no calculado';
    }

    /**
     * Obtiene los mensajes de estado para notificaciones después de calcular
     */
    public static function getCalculationStatusMessages(bool $wasCalculated): array
    {
        $action = $wasCalculated ? 'recalculado' : 'calculado';

        return [
            'present' => "Empleado presente — Cálculos {$action}s",
            'absent' => 'Empleado ausente',
            'on_leave' => 'Empleado con permiso o vacaciones',
            'holiday' => 'Día feriado',
            'weekend' => 'Fin de semana',
        ];
    }

    /**
     * Formatea los campos auditados para presentación en el historial de cambios.
     *
     * @param  string  $column  Nombre de la columna del audit ('old_values' | 'new_values')
     * @param  mixed  $auditRecord  Instancia del audit
     */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];
        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $fieldLabels = [
            'overtime_approved' => 'HE aprobadas',
            'second_shift_regular' => 'Doble turno como jornada normal',
            'tardiness_deduction_approved' => 'Desc. tardanza aprobado',
            'notes' => 'Notas',
            'manual_adjustment' => 'Ajuste manual',
            'extra_hours_diurnas' => 'Hrs extra diurnas',
            'extra_hours_nocturnas' => 'Hrs extra nocturnas',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $label = $fieldLabels[$key] ?? Str::headline($key);
            $formatted = $this->formatAuditValue($key, $value);
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> <span class=\"font-medium\">{$formatted}</span></li>";
        }
        $html .= '</ul>';

        return new HtmlString($html);
    }

    /** Formatea un valor individual del audit a texto legible. */
    private function formatAuditValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'overtime_approved', 'second_shift_regular', 'tardiness_deduction_approved', 'manual_adjustment' => $value ? 'Sí' : 'No',
            'extra_hours_diurnas', 'extra_hours_nocturnas' => number_format((float) $value, 2).' hrs',
            'notes' => Str::limit((string) $value, 120),
            default => (string) $value,
        };
    }
}
