<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    /** Red de seguridad: ninguna ruta puede borrar un departamento con cargos o contratos asociados. */
    protected static function booted(): void
    {
        static::deleting(function (Department $department): void {
            if ($department->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar el departamento: tiene {$department->deletionBlockersSummary()}. Moverlos a otro departamento antes de eliminarlo.");
            }
        });

        static::updating(function (Department $department): void {
            if ($department->isDirty('is_active') && ! $department->is_active && $department->activeContractsCount() > 0) {
                throw new \DomainException("No se puede desactivar el departamento: tiene {$department->activeContractsCount()} contratos vigentes. Moverlos a otro departamento primero.");
            }
        });
    }

    protected $fillable = [
        'company_id',
        'name',
        'cost_center',
        'description',
        'is_active',
        'manager_id',
    ];

    /** @var array<string, mixed> Los departamentos nuevos nacen activos (igual que el default de la base). */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Definir la relación de pertenencia entre el departamento y la empresa a la que pertenece.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Definir la relación de uno a muchos entre el departamento y los cargos que pertenecen a él.
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /** Empleado que dirige el departamento. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * Plazas libres del departamento: suma de las vacantes de sus cargos activos que tienen plazas definidas.
     * Los cargos sin plazas definidas no cuentan.
     */
    public function vacanciesCount(): int
    {
        return (int) $this->positions()->active()->whereNotNull('headcount')->get()
            ->sum(fn (Position $position) => $position->vacanciesCount());
    }

    /** Limita a los departamentos activos (los inactivos no se ofrecen al asignar contratos). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('departments.is_active', true);
    }

    /** Activos, más el ya asignado (`$keepId`) para que un select de edición no quede vacío. */
    public function scopeAssignable(Builder $query, ?int $keepId = null): Builder
    {
        return $query->where(function (Builder $query) use ($keepId): void {
            $query->where('departments.is_active', true);

            if ($keepId !== null) {
                $query->orWhere('departments.id', $keepId);
            }
        });
    }

    /** Contratos vigentes (activos o suspendidos) registrados en el departamento. */
    public function activeContractsCount(): int
    {
        return $this->contracts()->whereIn('status', ['active', 'suspended'])->count();
    }

    /**
     * Qué impide desactivar el departamento: los contratos vigentes.
     *
     * @return array<string, int> Cantidad por motivo (solo los que tienen al menos uno).
     */
    public function deactivationBlockers(): array
    {
        return array_filter(['contratos vigentes' => $this->activeContractsCount()]);
    }

    /** Contratos (vigentes e históricos) registrados en el departamento. */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Qué impide eliminar el departamento o cambiarlo de empresa: cargos y contratos asociados.
     *
     * @return array<string, int> Cantidad por motivo (solo los que tienen al menos uno).
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'cargo' => $this->positions()->count(),
            'contrato' => $this->contracts()->count(),
        ]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "3 cargos y 12 contratos". */
    public function deletionBlockersSummary(): string
    {
        $parts = collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => $count === 1 ? "1 {$label}" : "{$count} {$label}s")
            ->values();

        return $parts->count() > 1
            ? $parts->slice(0, -1)->implode(', ').' y '.$parts->last()
            : (string) $parts->first();
    }

    /**
     * Definir la relación de muchos a muchos entre el departamento y los empleados a través de los contratos, permitiendo acceder a los empleados que trabajan en el departamento independientemente de su cargo específico.
     *
     * @return void
     */
    public function employees()
    {
        return $this->hasManyThrough(
            Employee::class,
            Contract::class,
            'department_id', // FK on contracts
            'id',            // FK on employees
            'id',            // local key on departments
            'employee_id'    // local key on contracts
        );
    }
}
