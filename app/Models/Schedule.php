<?php

namespace App\Models;

use App\Settings\PayrollSettings;
use Carbon\Carbon;
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
    ];

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
            'asignaciones de empleados (vigentes o históricas)' => $this->assignments()->count(),
            'empleados con este horario como horario fijo' => $this->employees()->count(),
        ]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "tiene 3 asignaciones de empleados". */
    public function deletionBlockersSummary(): string
    {
        $parts = collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => "{$count} {$label}")
            ->values();

        $text = $parts->count() > 1
            ? $parts->slice(0, -1)->implode(', ').' y '.$parts->last()
            : (string) $parts->first();

        return "tiene {$text}. Reasigne a esos empleados a otro horario y vuelva a intentarlo";
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
