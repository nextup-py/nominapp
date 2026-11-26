<?php

namespace App\Models;

use App\Support\BlockerSummary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** Turno de trabajo reutilizable dentro de un patrón de rotación. */
class ShiftTemplate extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Campos del turno que se auditan.
     *
     * @var array<int, string>
     */
    protected array $auditInclude = [
        'company_id', 'name', 'shift_type', 'color', 'is_day_off',
        'start_time', 'end_time', 'break_minutes', 'notes', 'is_active',
    ];

    /** Valores por defecto de un turno nuevo. */
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::updating(function (ShiftTemplate $shift): void {
            if ($shift->isDirty('is_active') && ! $shift->is_active && $shift->deactivationBlockers() !== []) {
                throw new \DomainException("No se puede desactivar el turno: {$shift->deactivationBlockersSummary()}.");
            }
        });

        static::deleting(function (ShiftTemplate $shift): void {
            if ($shift->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar el turno: {$shift->deletionBlockersSummary()}.");
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'color',
        'shift_type',
        'is_day_off',
        'start_time',
        'end_time',
        'break_minutes',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_day_off' => 'boolean',
        'is_active' => 'boolean',
        'break_minutes' => 'integer',
    ];

    /** Empresa a la que pertenece el turno. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Overrides que usan este turno. */
    public function overrides(): HasMany
    {
        return $this->hasMany(ShiftOverride::class, 'shift_id');
    }

    /** Solo turnos activos. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('shift_templates.is_active', true);
    }

    /** Activos, más el ya asignado (`$keepId`) para que un select de edición no quede vacío. */
    public function scopeAssignable(Builder $query, ?int $keepId = null): Builder
    {
        return $query->where(function (Builder $query) use ($keepId): void {
            $query->where('shift_templates.is_active', true);

            if ($keepId !== null) {
                $query->orWhere('shift_templates.id', $keepId);
            }
        });
    }

    /**
     * Ids de los patrones de rotación de la empresa cuya secuencia incluye este turno. Se filtra en PHP porque
     * `sequence` es un JSON que puede guardar los ids como número o como texto.
     *
     * @return array<int, int>
     */
    public function patternIdsUsing(): array
    {
        if (! $this->exists) {
            return [];
        }

        return RotationPattern::query()
            ->where('company_id', $this->company_id)
            ->get(['id', 'sequence'])
            ->filter(fn (RotationPattern $pattern): bool => in_array((string) $this->id, array_map('strval', $pattern->sequence ?? []), true))
            ->pluck('id')
            ->all();
    }

    /** Patrones de rotación que incluyen este turno en su ciclo. */
    public function rotationPatterns(): HasMany
    {
        return $this->hasMany(RotationPattern::class, 'company_id', 'company_id')
            ->whereIn('rotation_patterns.id', $this->patternIdsUsing());
    }

    /** Patrones activos que usan este turno. */
    public function activePatternsCount(): int
    {
        return $this->rotationPatterns()->where('rotation_patterns.is_active', true)->count();
    }

    /** Turnos puntuales (Planificador) con fecha de hoy o posterior que usan este turno. */
    public function upcomingOverridesCount(): int
    {
        return $this->overrides()->where('override_date', '>=', Carbon::today()->toDateString())->count();
    }

    /**
     * Motivos que impiden desactivar el turno: patrones de rotación activos que lo incluyen o turnos puntuales
     * de hoy en adelante. El historial (patrones inactivos, turnos puntuales pasados) no impide desactivar.
     *
     * @return array<string, int>
     */
    public function deactivationBlockers(): array
    {
        return array_filter([
            'patrón de rotación activo|patrones de rotación activos' => $this->activePatternsCount(),
            'turno puntual del Planificador de hoy en adelante|turnos puntuales del Planificador de hoy en adelante' => $this->upcomingOverridesCount(),
        ]);
    }

    /**
     * Motivos que impiden eliminar el turno: cualquier patrón o turno puntual que alguna vez lo usó (los
     * `shift_overrides` lo referencian y las jornadas pasadas se resuelven contra él).
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'patrón de rotación|patrones de rotación' => $this->rotationPatterns()->count(),
            'turno puntual del Planificador|turnos puntuales del Planificador' => $this->overrides()->count(),
        ]);
    }

    /** Texto legible de {@see self::deactivationBlockers()}. */
    public function deactivationBlockersSummary(): string
    {
        return 'está en '.BlockerSummary::format($this->deactivationBlockers()).'. Quitarlo de esos patrones o turnos primero';
    }

    /** Texto legible de {@see self::deletionBlockers()}; con solo historial, sugiere desactivar. */
    public function deletionBlockersSummary(): string
    {
        $text = 'está en '.BlockerSummary::format($this->deletionBlockers());

        return $this->deactivationBlockers() === []
            ? "{$text}. Eliminarlo borraría ese historial: para dejar de usarlo, desactivar el turno"
            : "{$text}. Quitarlo de esos patrones o turnos y, para dejar de usarlo, desactivarlo";
    }

    /**
     * Resumen del horario del turno, p. ej. `07:00–15:00 · 8 h netas`, o `Franco` si es día libre.
     */
    public function scheduleSummary(): string
    {
        if ($this->is_day_off) {
            return 'Franco';
        }

        if (! $this->start_time || ! $this->end_time) {
            return 'Sin horario';
        }

        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5)
            .' · '.Schedule::formatHours($this->netMinutes() / 60).' netas';
    }

    /**
     * Tipo de jornada sugerido para un horario (según las horas nocturnas de `payroll.shift_boundaries`), o null si
     * faltan la entrada o la salida. Solo se muestra como ayuda: no se aplica solo.
     */
    public static function suggestShiftTypeFor(?string $start, ?string $end): ?string
    {
        return Schedule::suggestShiftType([['is_active' => true, 'start_time' => $start, 'end_time' => $end]]);
    }

    /**
     * Uso de cada turno con dos consultas (patrones y turnos puntuales), para mostrarlo en el listado sin una
     * consulta por fila.
     *
     * @return array<int, array{patterns: int, overrides: int}>
     */
    public static function usageMap(): array
    {
        $map = [];

        foreach (RotationPattern::query()->get(['id', 'company_id', 'sequence']) as $pattern) {
            foreach (array_unique(array_map('intval', $pattern->sequence ?? [])) as $shiftId) {
                $map[$shiftId]['patterns'] = ($map[$shiftId]['patterns'] ?? 0) + 1;
            }
        }

        foreach (ShiftOverride::query()->selectRaw('shift_id, COUNT(*) AS total')->groupBy('shift_id')->pluck('total', 'shift_id') as $shiftId => $total) {
            $map[(int) $shiftId]['overrides'] = (int) $total;
        }

        return collect($map)->map(fn (array $row): array => ['patterns' => $row['patterns'] ?? 0, 'overrides' => $row['overrides'] ?? 0])->all();
    }

    /** Copia el turno bajo un nombre nuevo. No copia el uso en patrones ni turnos puntuales. */
    public function duplicate(string $name): self
    {
        $copy = $this->replicate(['created_at', 'updated_at']);
        $copy->name = $name;
        $copy->is_active = true;
        $copy->save();

        return $copy;
    }

    /** Pone en mayúscula solo la primera letra del nombre. */
    public static function capitalizeName(string $name): string
    {
        $name = trim($name);

        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($name, 1, null, 'UTF-8');
    }

    /** Presenta los valores del historial con etiquetas en español. */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];
        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $labels = [
            'company_id' => 'Empresa',
            'name' => 'Nombre',
            'shift_type' => 'Tipo de jornada',
            'color' => 'Color',
            'is_day_off' => 'Franco',
            'start_time' => 'Entrada',
            'end_time' => 'Salida',
            'break_minutes' => 'Descanso (min)',
            'notes' => 'Notas',
            'is_active' => 'Estado',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $text = match (true) {
                $value === null || $value === '' => '—',
                $key === 'is_active' => (bool) $value ? 'Activo' : 'Inactivo',
                $key === 'is_day_off' => (bool) $value ? 'Sí' : 'No',
                $key === 'shift_type' => static::getShiftTypeLabels()[$value] ?? (string) $value,
                $key === 'company_id' => Company::find($value)?->name ?? (string) $value,
                in_array($key, ['start_time', 'end_time'], true) => substr((string) $value, 0, 5),
                default => (string) $value,
            };
            $label = $labels[$key] ?? Str::headline($key);
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> <span class=\"font-medium\">".e($text).'</span></li>';
        }

        return new HtmlString($html.'</ul>');
    }

    /**
     * Retorna true si el turno cruza la medianoche (ej: 22:00 → 06:00).
     */
    public function crossesMidnight(): bool
    {
        if ($this->is_day_off || ! $this->start_time || ! $this->end_time) {
            return false;
        }

        return $this->end_time < $this->start_time;
    }

    /**
     * Duración neta del turno en minutos (descontando break_minutes).
     * Retorna 0 si es franco.
     */
    public function netMinutes(): int
    {
        if ($this->is_day_off || ! $this->start_time || ! $this->end_time) {
            return 0;
        }

        $start = Carbon::createFromTimeString($this->start_time);
        $end = Carbon::createFromTimeString($this->end_time);

        if ($end->lte($start)) {
            $end->addDay(); // turno cruza medianoche
        }

        return (int) $start->diffInMinutes($end) - $this->break_minutes;
    }

    // ──────────────────────────────────────────
    // Helpers estáticos para labels y colores
    // ──────────────────────────────────────────

    /** @return array<string, string> */
    public static function getShiftTypeOptions(): array
    {
        return [
            'diurno' => 'Diurno (06:00 - 20:00)',
            'nocturno' => 'Nocturno (20:00 - 06:00)',
            'mixto' => 'Mixto',
        ];
    }

    /** @return array<string, string> */
    public static function getShiftTypeLabels(): array
    {
        return [
            'diurno' => 'Diurno',
            'nocturno' => 'Nocturno',
            'mixto' => 'Mixto',
        ];
    }

    /** @return array<string, string> */
    public static function getShiftTypeColors(): array
    {
        return [
            'diurno' => 'success',
            'nocturno' => 'info',
            'mixto' => 'warning',
        ];
    }

    /** @return array<string, string> */
    public static function getReasonTypeLabels(): array
    {
        return [
            'cambio_turno' => 'Cambio de turno',
            'guardia_extra' => 'Guardia extra',
            'permiso' => 'Permiso',
            'reposo' => 'Reposo médico',
            'otro' => 'Otro',
        ];
    }
}
