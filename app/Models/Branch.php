<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'city',
        'coordinates',
    ];

    protected $casts = [
        'coordinates' => 'array',
    ];

    /** Red de seguridad: ninguna ruta puede borrar una sucursal con empleados, terminales o fallas de marcación asociadas. */
    protected static function booted(): void
    {
        static::deleting(function (Branch $branch): void {
            if ($branch->deletionBlockers() !== []) {
                throw new \DomainException("No se puede eliminar la sucursal: tiene {$branch->deletionBlockersSummary()}. Reasígnelos antes de eliminarla.");
            }
        });
    }

    /**
     * Empresa a la que pertenece la sucursal
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Empleados de la sucursal
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Empleados activos de la sucursal
     */
    public function activeEmployees(): HasMany
    {
        return $this->hasMany(Employee::class)->where('status', 'active');
    }

    /**
     * Terminales de marcación de la sucursal
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }

    /**
     * Cantidad de registros que impiden eliminar la sucursal, por tipo. Vacío si se puede eliminar.
     *
     * Los terminales tienen FK `restrict` (el borrado fallaría en la base) y las fallas de marcación
     * perderían su sucursal en silencio (`nullOnDelete`): ninguna debe quedar sin aviso.
     *
     * @return array<string, int>
     */
    public function deletionBlockers(): array
    {
        return array_filter([
            'empleados' => $this->employees()->count(),
            'terminales' => $this->terminals()->count(),
            'fallas de marcación' => AttendanceMarkFailure::where('branch_id', $this->id)->count(),
        ]);
    }

    /** Texto legible de {@see self::deletionBlockers()}, p. ej. "3 empleados y 1 terminales". */
    public function deletionBlockersSummary(): string
    {
        $parts = collect($this->deletionBlockers())
            ->map(fn (int $count, string $label) => "{$count} {$label}")
            ->values();

        return $parts->count() > 1
            ? $parts->slice(0, -1)->implode(', ').' y '.$parts->last()
            : (string) $parts->first();
    }
}
