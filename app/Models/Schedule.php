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

class Schedule extends Model
{
    protected $table = 'schedules';

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
     * Empleados con asignación activa a este horario hoy (nuevo sistema).
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
                ->orWhere('employee_schedule_assignments.valid_until', '>=', $today)
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

    /** Asignaciones que todavía rigen o van a regir (sin fecha de fin o con fin hoy o posterior). */
    public function currentAssignmentsCount(): int
    {
        return $this->assignments()
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', Carbon::today()->toDateString()))
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
