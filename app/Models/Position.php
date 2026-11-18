<?php

namespace App\Models;

use App\Services\MobileMarkingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    ];

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
        return ['mobile_marking_allowed' => 'boolean', 'marks_breaks' => 'boolean', 'is_active' => 'boolean'];
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
        return array_filter(['contrato' => $this->contracts()->count()]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "12 contratos". */
    public function deletionBlockersSummary(): string
    {
        return collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => $count === 1 ? "1 {$label}" : "{$count} {$label}s")
            ->implode(' y ');
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
