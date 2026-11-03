<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Representa un hijo registrado a cargo de un empleado.
 * Usado para calcular la bonificación familiar (Arts. 253-262 CLT).
 */
class EmployeeChild extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'first_name',
        'last_name',
        'birth_date',
        'ci',
        'birth_certificate_path',
        'is_placeholder',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_placeholder' => 'boolean',
    ];

    /** Empleado al que pertenece este hijo. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Nombre completo del hijo. */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /** Años cumplidos del hijo, o null si aún no se cargó su fecha de nacimiento. */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    /** Hijos de relleno pendientes de completar por RR.HH. (no generan bonificación). */
    public function scopePlaceholders(Builder $query): Builder
    {
        return $query->where('is_placeholder', true);
    }

    /** Indica si el registro es un placeholder pendiente de completar. */
    public function isPending(): bool
    {
        return $this->is_placeholder || $this->birth_date === null;
    }

    /**
     * Indica si el hijo es menor de 18 años y por tanto elegible
     * para la bonificación familiar. Un placeholder nunca es elegible.
     */
    public function getIsEligibleAttribute(): bool
    {
        if ($this->isPending()) {
            return false;
        }

        return $this->birth_date->gt(now()->subYears(18));
    }

    /** URL pública del certificado de nacimiento adjunto, si existe. */
    public function getBirthCertificateUrlAttribute(): ?string
    {
        return $this->birth_certificate_path
            ? Storage::disk('public')->url($this->birth_certificate_path)
            : null;
    }
}
