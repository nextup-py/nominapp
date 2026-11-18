<?php

namespace App\Models;

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
    }

    protected $fillable = [
        'company_id',
        'name',
        'cost_center',
        'description',
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
            'cargos' => $this->positions()->count(),
            'contratos' => $this->contracts()->count(),
        ]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "3 cargos y 12 contratos". */
    public function deletionBlockersSummary(): string
    {
        $parts = collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => "{$count} {$label}")
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
