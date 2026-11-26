<?php

namespace App\Models;

use App\Settings\PayrollSettings;
use App\Support\BlockerSummary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Events\AuditCustom;

class Schedule extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'schedules';

    /**
     * Campos propios del horario que se auditan. Los días y descansos se registran aparte, en un solo evento por
     * guardado, con {@see self::recordDaysAudit()}.
     *
     * @var array<int, string>
     */
    protected array $auditInclude = ['name', 'description', 'shift_type', 'is_active'];

    /** Minutos nocturnos en un día a partir de los cuales la jornada se sugiere como nocturna (3,5 h). */
    public const NIGHT_SHIFT_THRESHOLD_MINUTES = 210;

    /** Cantidad de días activos igual o menor a la cual el formulario avisa que quizá es un turno partido. */
    public const FEW_ACTIVE_DAYS = 2;

    protected static function booted(): void
    {
        static::updating(function (Schedule $schedule): void {
            if ($schedule->isDirty('is_active') && ! $schedule->is_active && $schedule->deactivationBlockers() !== []) {
                throw new \DomainException("No se puede desactivar el horario: {$schedule->deactivationBlockersSummary()}.");
            }
        });

        static::deleting(function (Schedule $schedule): void {
            if ($schedule->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar el horario: {$schedule->deletionBlockersSummary()}.");
            }
        });
    }

    // Campos asignables
    protected $fillable = [
        'name',
        'description',
        'shift_type',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Valores por defecto de un horario nuevo. */
    protected $attributes = ['is_active' => true];

    /** Solo horarios activos. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('schedules.is_active', true);
    }

    /** Activos, más el ya asignado (`$keepId`) para que un select de edición no quede vacío. */
    public function scopeAssignable(Builder $query, ?int $keepId = null): Builder
    {
        return $query->where(function (Builder $query) use ($keepId): void {
            $query->where('schedules.is_active', true);

            if ($keepId !== null) {
                $query->orWhere('schedules.id', $keepId);
            }
        });
    }

    /**
     * Cargos que tienen este horario como sugerido (`positions.default_schedule_id`).
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'default_schedule_id');
    }

    /**
     * Todos los días del horario (7 en total).
     */
    public function days(): HasMany
    {
        return $this->hasMany(ScheduleDay::class);
    }

    /**
     * Días laborales activos del horario (is_active = true), ordenados por día de semana.
     */
    public function activeDays(): HasMany
    {
        return $this->hasMany(ScheduleDay::class)
            ->where('is_active', true)
            ->orderBy('day_of_week');
    }

    /**
     * Descansos a través de los días
     */
    public function breaks(): HasManyThrough
    {
        return $this->hasManyThrough(
            ScheduleBreak::class,
            ScheduleDay::class,
            'schedule_id',        // FK en schedule_days
            'schedule_day_id',     // FK en schedule_breaks
            'id',                  // PK en schedules
            'id'                   // PK en schedule_days
        );
    }

    /**
     * Empleados asignados via campo legacy schedule_id.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Empleados que siguen asignados a este horario después de hoy (nuevo sistema): sin fecha de fin o con fin
     * posterior a hoy. Quien ya tiene la asignación cerrada con fecha de hoy (lo que deja «Remover») no figura,
     * aunque hoy todavía trabaje con este horario; queda en el historial de asignaciones.
     */
    public function currentEmployees(): HasManyThrough
    {
        $today = Carbon::today()->toDateString();

        return $this->hasManyThrough(
            Employee::class,
            EmployeeScheduleAssignment::class,
            'schedule_id',  // FK en employee_schedule_assignments
            'id',           // FK en employees
            'id',           // PK en schedules
            'employee_id',  // PK local en employee_schedule_assignments
        )
            ->where('employee_schedule_assignments.valid_from', '<=', $today)
            ->where(fn ($q) => $q
                ->whereNull('employee_schedule_assignments.valid_until')
                ->orWhere('employee_schedule_assignments.valid_until', '>', $today)
            );
    }

    /**
     * Asignaciones de empleados a este horario (vigentes e históricas).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class);
    }

    /**
     * Motivos que impiden eliminar el horario: borrarlo arrastraría en cascada el historial de
     * asignaciones, y las jornadas pasadas se recalculan contra él.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'asignación de empleado (vigente o finalizada)|asignaciones de empleados (vigentes o finalizadas)' => $this->assignments()->count(),
            'empleado con este horario como horario fijo|empleados con este horario como horario fijo' => $this->employees()->count(),
        ]);
    }

    /**
     * Asignaciones que seguirán rigiendo después de hoy (sin fecha de fin o con fin posterior a hoy). Una que termina
     * hoy —lo que deja «Remover»— ya está cerrada: no impide desactivar el horario.
     */
    public function currentAssignmentsCount(): int
    {
        return $this->assignments()
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', Carbon::today()->toDateString()))
            ->count();
    }

    /** Asignaciones ya finalizadas (historial de quién tuvo este horario y hasta cuándo). */
    public function endedAssignmentsCount(): int
    {
        return $this->assignments()->count() - $this->currentAssignmentsCount();
    }

    /**
     * Motivos que impiden desactivar el horario: asignaciones vigentes o futuras, o empleados que lo tienen como
     * horario fijo. El historial finalizado no impide desactivar.
     *
     * @return array<string, int>
     */
    public function deactivationBlockers(): array
    {
        return array_filter([
            'asignación de empleado vigente o futura|asignaciones de empleados vigentes o futuras' => $this->currentAssignmentsCount(),
            'empleado con este horario como horario fijo|empleados con este horario como horario fijo' => $this->employees()->count(),
        ]);
    }

    /** Texto legible de {@see self::deactivationBlockers()}. */
    public function deactivationBlockersSummary(): string
    {
        return 'tiene '.BlockerSummary::format($this->deactivationBlockers()).'. Asignar a esos empleados otro horario primero';
    }

    /**
     * Texto legible de {@see self::deletionBlockers()}, p. ej. "tiene 3 asignaciones de empleados". Con solo
     * historial finalizado, indica que el camino es desactivar el horario.
     */
    public function deletionBlockersSummary(): string
    {
        $text = 'tiene '.BlockerSummary::format($this->deletionBlockers());

        return $this->deactivationBlockers() === []
            ? "{$text}. Eliminarlo borraría ese historial: para dejar de usarlo, desactivar el horario"
            : "{$text}. Asignar a esos empleados otro horario y, para dejar de usarlo, desactivarlo";
    }

    /**
     * Resume cada día del horario como texto legible, p. ej. `['Lunes' => '23:00–07:00 · Cena 02:00–02:30', 'Domingo' => 'Libre']`.
     * Sirve para comparar el antes y el después de un guardado en el historial de cambios.
     *
     * @return array<string, string>
     */
    public function daysSummary(): array
    {
        $summary = [];

        foreach ($this->days()->with('breaks')->orderBy('day_of_week')->get() as $day) {
            $text = 'Libre';

            if ($day->is_active) {
                $text = Carbon::parse($day->start_time)->format('H:i').'–'.Carbon::parse($day->end_time)->format('H:i');

                foreach ($day->breaks->sortBy('start_time') as $break) {
                    $text .= ' · '.$break->name.' '.Carbon::parse($break->start_time)->format('H:i').'–'.Carbon::parse($break->end_time)->format('H:i');
                }
            }

            $summary[$day->day_of_week_name] = $text;
        }

        return $summary;
    }

    /**
     * Registra en el historial un cambio de días, horas o descansos: un solo evento por guardado con **solo los días
     * que cambiaron** (antes y después). No hace nada si no cambió ninguno.
     *
     * @param  array<string, string>  $before  Resumen previo, de {@see self::daysSummary()}.
     * @param  array<string, string>  $after  Resumen posterior.
     */
    public function recordDaysAudit(array $before, array $after): void
    {
        $old = [];
        $new = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $day) {
            if (($before[$day] ?? null) !== ($after[$day] ?? null)) {
                $old[$day] = $before[$day] ?? 'Sin dato';
                $new[$day] = $after[$day] ?? 'Sin dato';
            }
        }

        if ($old === []) {
            return;
        }

        $this->auditEvent = 'updated';
        $this->isCustomEvent = true;
        $this->auditCustomOld = ['days' => $old];
        $this->auditCustomNew = ['days' => $new];

        Event::dispatch(new AuditCustom($this));

        $this->isCustomEvent = false;
        $this->auditCustomOld = null;
        $this->auditCustomNew = null;
    }

    /**
     * Presenta los valores de un registro de auditoría (`old_values`/`new_values`) con etiquetas en español.
     *
     * @param  string  $column  `old_values` o `new_values`.
     * @param  mixed  $auditRecord  Registro de auditoría.
     */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];
        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $labels = [
            'name' => 'Nombre',
            'description' => 'Descripción',
            'shift_type' => 'Tipo de jornada',
            'is_active' => 'Estado',
            'days' => 'Días',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $label = $labels[$key] ?? Str::headline($key);
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> ".$this->formatAuditValue($key, $value).'</li>';
        }

        return new HtmlString($html.'</ul>');
    }

    /** Formatea un valor individual del historial a HTML seguro. */
    private function formatAuditValue(string $key, mixed $value): string
    {
        if ($key === 'days' && is_array($value)) {
            $html = '<ul class="ml-3 space-y-0.5">';
            foreach ($value as $day => $text) {
                $html .= '<li><span class="text-gray-500">'.e($day).':</span> <span class="font-medium">'.e($text).'</span></li>';
            }

            return $html.'</ul>';
        }

        $text = match (true) {
            $value === null || $value === '' => '—',
            $key === 'is_active' => (bool) $value ? 'Activo' : 'Inactivo',
            $key === 'shift_type' => static::getShiftTypeLabels()[$value] ?? (string) $value,
            default => (string) $value,
        };

        return '<span class="font-medium">'.e($text).'</span>';
    }

    /**
     * Copia el horario con todos sus días y descansos bajo un nombre nuevo. No copia asignaciones.
     */
    public function duplicate(string $name): self
    {
        return DB::transaction(function () use ($name): self {
            $copy = new self([
                'name' => $name,
                'description' => $this->description,
                'shift_type' => $this->shift_type,
            ]);
            $copy->save();

            foreach ($this->days()->with('breaks')->get() as $day) {
                $dayCopy = $day->replicate(['created_at', 'updated_at']);
                $dayCopy->schedule_id = $copy->id;
                $dayCopy->save();

                foreach ($day->breaks as $break) {
                    $breakCopy = $break->replicate(['created_at', 'updated_at']);
                    $breakCopy->schedule_day_id = $dayCopy->id;
                    $breakCopy->save();
                }
            }

            return $copy;
        });
    }

    /**
     * Sugiere el tipo de jornada según las horas de los días activos, usando la franja nocturna de
     * `payroll.shift_boundaries`: sin horas nocturnas es diurna; si algún día supera
     * {@see self::NIGHT_SHIFT_THRESHOLD_MINUTES} nocturnos es nocturna; en otro caso, mixta.
     * Es solo una sugerencia: devuelve null si no hay días con horas completas.
     *
     * @param  iterable<int, array{is_active?: mixed, start_time?: mixed, end_time?: mixed}>  $days
     */
    public static function suggestShiftType(iterable $days): ?string
    {
        $toMinutes = function (mixed $time): ?int {
            if (! is_string($time) || ! preg_match('/(\d{1,2}):(\d{2})/', $time, $m)) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $dayStart = $toMinutes(config('payroll.shift_boundaries.day_start', '06:00')) ?? 360;
        $dayEnd = $toMinutes(config('payroll.shift_boundaries.day_end', '20:00')) ?? 1200;

        $maxNight = null;

        foreach ($days as $day) {
            if (! ($day['is_active'] ?? false)) {
                continue;
            }

            $start = $toMinutes($day['start_time'] ?? null);
            $end = $toMinutes($day['end_time'] ?? null);

            if ($start === null || $end === null || $start === $end) {
                continue;
            }

            if ($end <= $start) {
                $end += 1440;
            }

            $night = 0;
            foreach ([-1, 0, 1] as $offset) {
                $from = max($start, $dayEnd + $offset * 1440);
                $to = min($end, $dayStart + ($offset + 1) * 1440);
                $night += max(0, $to - $from);
            }

            $maxNight = max($maxNight ?? 0, $night);
        }

        return match (true) {
            $maxNight === null => null,
            $maxNight === 0 => 'diurno',
            $maxNight >= self::NIGHT_SHIFT_THRESHOLD_MINUTES => 'nocturno',
            default => 'mixto',
        };
    }

    /**
     * Retorna true si el día de la semana dado es libre (inactivo) en este horario.
     *
     * @param  int  $dayOfWeek  1=Lunes … 7=Domingo
     */
    public function isDayOff(int $dayOfWeek): bool
    {
        $day = $this->days()->where('day_of_week', $dayOfWeek)->first();

        return $day ? ! $day->is_active : true;
    }

    /**
     * Horas netas por semana (descansos descontados) y cantidad de días con turno. Usa `days` con sus descansos:
     * precargarlos (`days.breaks`) para no consultar por día.
     *
     * @return array{days: int, hours: float}
     */
    public function weeklySummary(): array
    {
        $active = $this->days->where('is_active', true);

        return [
            'days' => $active->count(),
            'hours' => round($active->sum(fn (ScheduleDay $day): float => $day->scheduled_hours), 2),
        ];
    }

    /** Indica si algún día con turno termina al día siguiente (p. ej. 23:00 a 07:00). */
    public function crossesMidnight(): bool
    {
        return $this->days->where('is_active', true)->contains(fn (ScheduleDay $day): bool => $day->crossesMidnight());
    }

    /**
     * Texto del resumen semanal, p. ej. «6 días · 48 h por semana».
     */
    public function weeklySummaryLabel(): string
    {
        ['days' => $days, 'hours' => $hours] = $this->weeklySummary();

        if ($days === 0) {
            return 'Sin días con turno';
        }

        $hoursText = rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ',');

        return $days.' '.($days === 1 ? 'día' : 'días').' · '.$hoursText.' h por semana';
    }

    public function getMonthlyHours(): float
    {
        $settings = app(PayrollSettings::class);

        return match ($this->shift_type) {
            'nocturno' => $settings->monthly_hours_nocturno,
            'mixto' => $settings->monthly_hours_mixto,
            default => $settings->monthly_hours,
        };
    }

    public function getDailyMaxHours(): float
    {
        $settings = app(PayrollSettings::class);

        return match ($this->shift_type) {
            'nocturno' => $settings->daily_hours_nocturno,
            'mixto' => $settings->daily_hours_mixto,
            default => $settings->daily_hours,
        };
    }

    public static function getShiftTypeOptions(): array
    {
        return [
            'diurno' => 'Diurno (06:00 - 20:00)',
            'nocturno' => 'Nocturno (20:00 - 06:00)',
            'mixto' => 'Mixto',
        ];
    }

    public static function getShiftTypeLabels(): array
    {
        return [
            'diurno' => 'Diurno',
            'nocturno' => 'Nocturno',
            'mixto' => 'Mixto',
        ];
    }

    public static function getShiftTypeColors(): array
    {
        return [
            'diurno' => 'success',
            'nocturno' => 'info',
            'mixto' => 'warning',
        ];
    }
}
