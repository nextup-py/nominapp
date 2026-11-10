<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
    ];

    protected $casts = [
        'birth_date' => 'date',
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

    /** Edad máxima (exclusiva) para que un hijo genere bonificación familiar. */
    public const MAX_AGE = 18;

    /** Años cumplidos del hijo. */
    public function getAgeAttribute(): int
    {
        return $this->birth_date->age;
    }

    /**
     * Indica si el hijo es menor de 18 años y por tanto elegible
     * para la bonificación familiar.
     */
    public function getIsEligibleAttribute(): bool
    {
        return $this->birth_date->gt(now()->subYears(18));
    }

    /**
     * Indica si el hijo tiene un respaldo documental: cédula del menor o certificado de nacimiento adjunto.
     * Sin respaldo no genera bonificación familiar.
     */
    public function hasSupport(): bool
    {
        return filled($this->ci) || filled($this->birth_certificate_path);
    }

    /**
     * Indica si el hijo genera bonificación en la fecha dada (por lo general, el fin del período de nómina):
     * nacido hasta esa fecha, menor de 18 años a esa fecha y con respaldo documental.
     */
    public function isEligibleAt(CarbonInterface $date): bool
    {
        return $this->birth_date->lte($date)
            && $this->birth_date->gt($date->copy()->subYears(self::MAX_AGE))
            && $this->hasSupport();
    }

    /** Hijos con cédula del menor o certificado adjunto. */
    public function scopeWithSupport(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->whereNotNull('ci')->where('ci', '!=', ''))
            ->orWhere(fn (Builder $q) => $q->whereNotNull('birth_certificate_path')->where('birth_certificate_path', '!=', '')));
    }

    /** URL pública del certificado de nacimiento adjunto, si existe. */
    public function getBirthCertificateUrlAttribute(): ?string
    {
        return $this->birth_certificate_path
            ? Storage::disk('public')->url($this->birth_certificate_path)
            : null;
    }
}
