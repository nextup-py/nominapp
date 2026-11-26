<?php

namespace App\Models;

use App\Support\BlockerSummary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/** Patrón de rotación: ciclo ordenado de turnos definido como secuencia JSON de IDs. */
class RotationPattern extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Campos del patrón que se auditan.
     *
     * @var array<int, string>
     */
    protected array $auditInclude = ['company_id', 'name', 'description', 'sequence', 'is_active'];

    /** Valores por defecto de un patrón nuevo. */
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::updating(function (RotationPattern $pattern): void {
            if ($pattern->isDirty('is_active') && ! $pattern->is_active && $pattern->deactivationBlockers() !== []) {
                throw new \DomainException("No se puede desactivar el patrón: {$pattern->deactivationBlockersSummary()}.");
            }
        });

        static::deleting(function (RotationPattern $pattern): void {
            if ($pattern->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar el patrón: {$pattern->deletionBlockersSummary()}.");
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'sequence',
        'is_active',
    ];

    protected $casts = [
        'sequence' => 'array',
        'is_active' => 'boolean',
    ];

    /** Empresa propietaria del patrón. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Asignaciones de empleados que usan este patrón. */
    public function assignments(): HasMany
    {
        return $this->hasMany(RotationAssignment::class, 'pattern_id');
    }

    /**
     * Empleados con asignación de rotación activa a este patrón hoy —
     * misma lógica que Schedule::currentEmployees(), adaptada a
     * rotation_assignments/pattern_id.
     */
    public function currentEmployees(): HasManyThrough
    {
        $today = Carbon::today()->toDateString();

        return $this->hasManyThrough(
            Employee::class,
            RotationAssignment::class,
            'pattern_id',   // FK en rotation_assignments
            'id',           // FK en employees
            'id',           // PK en rotation_patterns
            'employee_id',  // PK local en rotation_assignments
        )
            ->where('rotation_assignments.valid_from', '<=', $today)
            ->where(fn ($q) => $q
                ->whereNull('rotation_assignments.valid_until')
                ->orWhere('rotation_assignments.valid_until', '>', $today)
            );
    }

    /** Solo patrones activos. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('rotation_patterns.is_active', true);
    }

    /**
     * Asignaciones que seguirán rigiendo después de hoy (sin fin o con fin posterior a hoy). Una que termina hoy
     * —lo que deja «Remover»— ya está cerrada: no impide desactivar el patrón.
     */
    public function currentAssignmentsCount(): int
    {
        return $this->assignments()
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', Carbon::today()->toDateString()))
            ->count();
    }

    /**
     * Motivos que impiden desactivar el patrón: asignaciones vigentes o futuras (sin turno calculado si se
     * desactivara). El historial finalizado no lo impide.
     *
     * @return array<string, int>
     */
    public function deactivationBlockers(): array
    {
        return array_filter([
            'asignación de empleado vigente o futura|asignaciones de empleados vigentes o futuras' => $this->currentAssignmentsCount(),
        ]);
    }

    /**
     * Motivos que impiden eliminar el patrón: cualquier asignación, vigente o finalizada (las jornadas pasadas se
     * calculan contra ella).
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'asignación de empleado (vigente o finalizada)|asignaciones de empleados (vigentes o finalizadas)' => $this->assignments()->count(),
        ]);
    }

    /** Texto legible de {@see self::deactivationBlockers()}. */
    public function deactivationBlockersSummary(): string
    {
        return 'tiene '.BlockerSummary::format($this->deactivationBlockers()).'. Asignar a esos empleados otro patrón u horario primero';
    }

    /** Texto legible de {@see self::deletionBlockers()}; con solo historial, sugiere desactivar. */
    public function deletionBlockersSummary(): string
    {
        $text = 'tiene '.BlockerSummary::format($this->deletionBlockers());

        return $this->deactivationBlockers() === []
            ? "{$text}. Eliminarlo borraría ese historial: para dejar de usarlo, desactivar el patrón"
            : "{$text}. Asignar a esos empleados otro patrón y, para dejar de usarlo, desactivarlo";
    }

    /**
     * Turnos de la secuencia por id, cargados de una vez para armar resúmenes sin consulta por fila.
     *
     * @param  iterable<int, RotationPattern>  $patterns
     * @return Collection<int, ShiftTemplate>
     */
    public static function shiftsFor(iterable $patterns): Collection
    {
        $ids = collect($patterns)->flatMap(fn (RotationPattern $pattern) => $pattern->sequence ?? [])->map(fn ($id) => (int) $id)->unique()->values();

        return ShiftTemplate::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Resumen del ciclo, p. ej. «5 Mañana, 2 Franco · 7 días», contando los días por turno en orden de aparición.
     *
     * @param  Collection<int, ShiftTemplate>|null  $shifts  Turnos ya cargados ({@see self::shiftsFor()}).
     */
    public function cycleSummary(?Collection $shifts = null): string
    {
        $shifts ??= static::shiftsFor([$this]);
        $counts = collect($this->sequence ?? [])
            ->map(fn ($id) => $shifts->get((int) $id)?->name ?? 'Turno eliminado')
            ->countBy();

        if ($counts->isEmpty()) {
            return 'Sin días';
        }

        return $counts->map(fn (int $days, string $name): string => "{$days} {$name}")->implode(', ')
            .' · '.$this->cycle_length.' '.($this->cycle_length === 1 ? 'día' : 'días');
    }

    /**
     * Cada día del ciclo con su turno, para el detalle: `[['day' => 1, 'name' => 'Mañana', 'summary' => '07:00–15:00 · 7 h netas', 'color' => '#...']]`.
     *
     * @param  Collection<int, ShiftTemplate>|null  $shifts
     * @return array<int, array{day: int, name: string, summary: string, color: string|null, is_day_off: bool}>
     */
    public function cycleDays(?Collection $shifts = null): array
    {
        $shifts ??= static::shiftsFor([$this]);

        return collect($this->sequence ?? [])->values()->map(function ($id, int $index) use ($shifts): array {
            $shift = $shifts->get((int) $id);

            return [
                'day' => $index + 1,
                'name' => $shift?->name ?? 'Turno eliminado',
                'summary' => $shift?->scheduleSummary() ?? '—',
                'color' => $shift?->color,
                'is_day_off' => (bool) $shift?->is_day_off,
            ];
        })->all();
    }

    /** Copia el patrón (ciclo y descripción) bajo un nombre nuevo. No copia las asignaciones. */
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
        return ShiftTemplate::capitalizeName($name);
    }

    /** Presenta los valores del historial con etiquetas en español (la secuencia, con los nombres de los turnos). */
    public function formatAuditFieldsForPresentation(string $column, mixed $auditRecord): HtmlString
    {
        $values = $auditRecord->{$column} ?? [];
        if (empty($values)) {
            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
        }

        $labels = [
            'company_id' => 'Empresa',
            'name' => 'Nombre',
            'description' => 'Descripción',
            'sequence' => 'Ciclo',
            'is_active' => 'Estado',
        ];

        $html = '<ul class="space-y-0.5 text-sm">';
        foreach ($values as $key => $value) {
            $text = match (true) {
                $key === 'sequence' => collect(is_string($value) ? json_decode($value, true) : $value)
                    ->map(fn ($id) => ShiftTemplate::find((int) $id)?->name ?? 'Turno eliminado')
                    ->implode(' → ') ?: '—',
                $value === null || $value === '' => '—',
                $key === 'is_active' => (bool) $value ? 'Activo' : 'Inactivo',
                $key === 'company_id' => Company::find($value)?->name ?? (string) $value,
                default => (string) $value,
            };
            $label = $labels[$key] ?? Str::headline($key);
            $html .= "<li><span class=\"text-gray-500\">{$label}:</span> <span class=\"font-medium\">".e($text).'</span></li>';
        }

        return new HtmlString($html.'</ul>');
    }

    /**
     * Largo del ciclo en días (derivado del array sequence).
     */
    public function getCycleLengthAttribute(): int
    {
        return count($this->sequence ?? []);
    }

    /**
     * Retorna el ShiftTemplate ID para la posición dada del ciclo.
     *
     * @param  int  $position  Posición 0-based dentro del ciclo.
     */
    public function shiftIdAtPosition(int $position): ?int
    {
        $seq = $this->sequence ?? [];

        if (empty($seq)) {
            return null;
        }

        return $seq[$position % count($seq)] ?? null;
    }
}
