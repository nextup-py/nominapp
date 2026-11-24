<?php

namespace App\Models;

use App\Services\MobileMarkingService;
use App\Support\BlockerSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Position extends Model
{
    protected $fillable = [
        'name',
        'department_id',
        'parent_id',
        'mobile_marking_allowed',
        'terminal_access',
        'marks_breaks',
        'is_active',
        'description',
        'salary_min',
        'salary_max',
        'headcount',
        'is_managerial',
        'overtime_allowed',
        'default_schedule_id',
    ];

    /** Horas extra permitidas cuando ni el cargo ni un nivel superior lo definen (comportamiento actual: se permiten). */
    public const OVERTIME_ALLOWED_DEFAULT = true;

    /** @var array<string, mixed> Los cargos nuevos nacen activos (igual que el default de la base). */
    protected $attributes = [
        'is_active' => true,
    ];

    public const TERMINAL_OWN = 'own';

    public const TERMINAL_ALL = 'all';

    public const TERMINAL_SELECTED = 'selected';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mobile_marking_allowed' => 'boolean',
            'marks_breaks' => 'boolean',
            'is_active' => 'boolean',
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'headcount' => 'integer',
            'is_managerial' => 'boolean',
            'overtime_allowed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Position $position): void {
            if ($position->isDirty('is_active') && ! $position->is_active && $position->activeContractsCount() > 0) {
                throw new \DomainException("No se puede desactivar el cargo: tiene {$position->activeContractsCount()} contratos vigentes. Moverlos a otro cargo (Fusionar) primero.");
            }
        });

        static::deleting(function (Position $position): void {
            if ($position->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar el cargo: tiene {$position->deletionBlockersSummary()}. Moverlos a otro cargo (Fusionar) antes de eliminarlo.");
            }
        });

        static::updated(function (Position $position): void {
            if ($position->wasChanged('terminal_access')) {
                $position->touchEmployees();
            }

            if ($position->wasChanged('mobile_marking_allowed')) {
                MobileMarkingService::revokeDisallowed(
                    Employee::query()->whereHas('activeContract', fn ($q) => $q->where('position_id', $position->id))
                );
            }
        });
    }

    /** @return array<string, string> Dónde pueden marcar en terminal quienes ocupan el cargo. */
    public static function getTerminalAccessOptions(): array
    {
        return [
            self::TERMINAL_OWN => 'Solo en su sucursal',
            self::TERMINAL_SELECTED => 'En su sucursal y en las que se elijan',
            self::TERMINAL_ALL => 'En cualquier sucursal',
        ];
    }

    /** Sucursales extra donde pueden marcar en terminal (solo con `terminal_access = selected`). */
    public function terminalBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'position_terminal_branch');
    }

    /**
     * Marca como modificados a los empleados que ocupan el cargo: la sincronización de los terminales es incremental
     * por `updated_at`, así que sin esto no se enterarían de que ahora (o ya no) deben reconocerlos.
     */
    public function touchEmployees(): void
    {
        Employee::query()
            ->whereHas('activeContract', fn ($q) => $q->where('position_id', $this->id))
            ->update(['updated_at' => now()]);
    }

    /** Limita a los cargos activos (los inactivos no se ofrecen al asignar contratos ni como superior). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('positions.is_active', true);
    }

    /** Activos de departamentos activos, más el ya asignado (`$keepId`) para que un select de edición no quede vacío. */
    public function scopeAssignable(Builder $query, ?int $keepId = null): Builder
    {
        return $query->where(function (Builder $query) use ($keepId): void {
            $query->where(fn (Builder $active) => $active
                ->where('positions.is_active', true)
                ->whereHas('department', fn (Builder $department) => $department->where('departments.is_active', true)));

            if ($keepId !== null) {
                $query->orWhere('positions.id', $keepId);
            }
        });
    }

    /** Horario que se sugiere al asignar horario a quien ocupa el cargo (nunca se asigna solo). */
    public function defaultSchedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'default_schedule_id');
    }

    /** Horas extra permitidas para el cargo: el valor propio o, si hereda, {@see self::OVERTIME_ALLOWED_DEFAULT}. */
    public function overtimeAllowed(): bool
    {
        return $this->overtime_allowed ?? self::OVERTIME_ALLOWED_DEFAULT;
    }

    /** Personas con contrato vigente (activo o suspendido) en el cargo. */
    public function occupiedCount(): int
    {
        return $this->activeContractsCount();
    }

    /** Plazas libres (`headcount` − ocupadas, nunca negativo), o null si el cargo no tiene plazas definidas. */
    public function vacanciesCount(): ?int
    {
        return $this->headcount === null ? null : max(0, $this->headcount - $this->occupiedCount());
    }

    /**
     * Aviso si `$salary` queda fuera del rango del cargo (solo informa; el contrato se guarda igual).
     *
     * @param  int|float|string|null  $salary  Salario mensual en Gs.
     */
    public function salaryRangeWarning(int|float|string|null $salary): ?string
    {
        if (! filled($salary) || ($this->salary_min === null && $this->salary_max === null)) {
            return null;
        }

        $amount = (int) $salary;
        $range = $this->salaryRangeLabel();

        if ($this->salary_min !== null && $amount < $this->salary_min) {
            return "Por debajo del rango del cargo ({$range})";
        }

        if ($this->salary_max !== null && $amount > $this->salary_max) {
            return "Por encima del rango del cargo ({$range})";
        }

        return null;
    }

    /** Rango salarial legible, p. ej. «Gs. 3.000.000 – 5.000.000», «desde Gs. 3.000.000» o «hasta Gs. 5.000.000». */
    public function salaryRangeLabel(): ?string
    {
        $format = fn (int $amount): string => number_format($amount, 0, ',', '.');

        return match (true) {
            $this->salary_min !== null && $this->salary_max !== null => 'Gs. '.$format($this->salary_min).' – '.$format($this->salary_max),
            $this->salary_min !== null => 'desde Gs. '.$format($this->salary_min),
            $this->salary_max !== null => 'hasta Gs. '.$format($this->salary_max),
            default => null,
        };
    }

    /**
     * Aviso si el cargo ya tiene todas sus plazas ocupadas (solo informa).
     *
     * @param  int|null  $ignoreContractId  Contrato que se está editando, para no contarlo dos veces.
     */
    public function headcountWarning(?int $ignoreContractId = null): ?string
    {
        if ($this->headcount === null) {
            return null;
        }

        $occupied = $this->contracts()
            ->whereIn('status', ['active', 'suspended'])
            ->when($ignoreContractId, fn ($query) => $query->whereKeyNot($ignoreContractId))
            ->count();

        return $occupied >= $this->headcount
            ? "El cargo ya tiene {$occupied} de {$this->headcount} plazas ocupadas"
            : null;
    }

    /**
     * Aviso de rango salarial para el formulario de contrato (solo salario mensual; el jornal no se compara).
     *
     * @param  int|string|null  $positionId  Cargo elegido.
     */
    public static function salaryHint(int|string|null $positionId, mixed $salary, ?string $salaryType): ?string
    {
        if (blank($positionId) || $salaryType === 'jornal') {
            return null;
        }

        return static::query()->find($positionId)?->salaryRangeWarning($salary);
    }

    /**
     * Aviso de plazas completas para el formulario de contrato.
     *
     * @param  int|string|null  $positionId  Cargo elegido.
     * @param  int|null  $ignoreContractId  Contrato que se está editando.
     */
    public static function headcountHint(int|string|null $positionId, ?int $ignoreContractId = null): ?string
    {
        return blank($positionId) ? null : static::query()->find($positionId)?->headcountWarning($ignoreContractId);
    }

    /** Contratos vigentes (activos o suspendidos) con este cargo. */
    public function activeContractsCount(): int
    {
        return $this->contracts()->whereIn('status', ['active', 'suspended'])->count();
    }

    /**
     * Qué impide desactivar el cargo: los contratos vigentes.
     *
     * @return array<string, int> Cantidad por motivo (solo los que tienen al menos uno).
     */
    public function deactivationBlockers(): array
    {
        return array_filter(['contratos vigentes' => $this->activeContractsCount()]);
    }

    /** Contratos (vigentes e históricos) con este cargo. */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Qué impide eliminar el cargo o cambiarlo de departamento: los contratos asociados.
     *
     * @return array<string, int> Cantidad por motivo (solo los que tienen al menos uno).
     */
    public function deletionBlockers(): array
    {
        return array_filter(['contrato|contratos' => $this->contracts()->count()]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "12 contratos". */
    public function deletionBlockersSummary(): string
    {
        return BlockerSummary::format($this->deletionBlockers());
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(
            Employee::class,
            Contract::class,
            'position_id', // FK en contracts
            'id',          // FK en employees
            'id',          // local key en positions
            'employee_id'  // local key en contracts
        )->where('contracts.status', 'active');
    }

    /**
     * Cargo padre al que reporta este cargo.
     */
    public function parent()
    {
        return $this->belongsTo(Position::class, 'parent_id');
    }

    /**
     * Cargos hijos que reportan a este cargo.
     */
    public function children()
    {
        return $this->hasMany(Position::class, 'parent_id');
    }

    /**
     * Obtiene todos los descendientes de forma recursiva.
     */
    public function getAllDescendants(): Collection
    {
        $descendants = collect();

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getAllDescendants());
        }

        return $descendants;
    }

    /**
     * Obtiene todos los IDs de descendientes (para prevenir ciclos).
     */
    public function getAllDescendantIds(): array
    {
        return $this->getAllDescendants()->pluck('id')->toArray();
    }

    /**
     * Verifica si este cargo es raíz (sin padre).
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Obtiene el nivel jerárquico del cargo (0 = raíz).
     */
    public function getHierarchyLevel(): int
    {
        $level = 0;
        $current = $this;

        while ($current->parent) {
            $level++;
            $current = $current->parent;
        }

        return $level;
    }

    /**
     * Obtiene las opciones de cargos con sus departamentos para selects
     */
    public static function getOptionsWithDepartment(?int $keepId = null): array
    {
        return static::with('department')
            ->assignable($keepId)
            ->get()
            ->mapWithKeys(function ($position) {
                $label = $position->name;
                if ($position->department) {
                    $label .= ' - '.$position->department->name;
                }

                return [$position->id => $label];
            })
            ->toArray();
    }
}
