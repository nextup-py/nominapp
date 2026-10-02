<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Deduction extends Model
{
    /** Código de la deducción generada por suspensiones disciplinarias. */
    public const CODE_DISCIPLINARY_SUSPENSION = 'SUS-DIS';

    /** Código de la deducción generada por ausencias injustificadas. */
    public const CODE_UNJUSTIFIED_ABSENCE = 'AUS-INJ';

    /**
     * Códigos de deducciones de sistema: las crean y gestionan los módulos (ausencias, suspensión,
     * préstamos, adelantos, mercadería) con un EmployeeDeduction puntual por evento. Nunca deben ser
     * obligatorias: asignarlas a un empleado como deducción permanente genera líneas en cero en cada nómina.
     *
     * @var array<int, string>
     */
    public const SYSTEM_CODES = [
        self::CODE_UNJUSTIFIED_ABSENCE,
        self::CODE_DISCIPLINARY_SUSPENSION,
        'PRE001',
        'ADE001',
        'MER001',
    ];

    protected $fillable = [
        'name',
        'code',
        'type',
        'apply_judicial_limit',
        'description',
        'calculation',
        'amount',
        'percent',
        'is_mandatory',
        'is_active',
        'affects_irp',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percent' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
        'affects_irp' => 'boolean',
        'apply_judicial_limit' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Deduction $deduction) {
            if ($deduction->isSystem()) {
                $deduction->is_mandatory = false;
            }
        });
    }

    /** Indica si es una deducción de sistema (ver `SYSTEM_CODES`). */
    public function isSystem(): bool
    {
        return in_array($this->code, self::SYSTEM_CODES, true);
    }

    /**
     * Deducciones activas que se asignan automáticamente a empleados nuevos:
     * obligatorias, activas y que no sean de sistema.
     */
    public function scopeMandatoryAssignable(Builder $query): Builder
    {
        return $query
            ->where('is_mandatory', true)
            ->where('is_active', true)
            ->whereNotIn('code', self::SYSTEM_CODES);
    }

    /**
     * Todos los empleados con esta deducción (historial completo).
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_deductions')
            ->using(EmployeeDeduction::class)
            ->withPivot(['start_date', 'end_date', 'custom_amount', 'notes'])
            ->withTimestamps();
    }

    /**
     * Empleados activos con esta deducción: ya iniciadas y sin fecha de fin vencida.
     */
    public function activeEmployees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_deductions')
            ->using(EmployeeDeduction::class)
            ->withPivot(['start_date', 'end_date', 'custom_amount', 'notes'])
            ->withTimestamps()
            ->wherePivot('start_date', '<=', now())
            ->where(fn ($q) => $q
                ->whereNull('employee_deductions.end_date')
                ->orWhere('employee_deductions.end_date', '>=', now())
            );
    }

    /**
     * Relación directa con la tabla pivot EmployeeDeduction
     * Útil para acceder al historial completo y hacer queries complejas
     */
    public function employeeDeductions()
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    /**
     * Obtener solo las asignaciones activas.
     */
    public function activeEmployeeDeductions()
    {
        return $this->hasMany(EmployeeDeduction::class)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    /**
     * Obtener solo las asignaciones inactivas: con fecha de fin ya vencida.
     */
    public function inactiveEmployeeDeductions()
    {
        return $this->hasMany(EmployeeDeduction::class)
            ->whereNotNull('end_date')
            ->where('end_date', '<', now());
    }

    // -------------------------------------------------------------------------
    // Métodos estáticos — labels, colores, opciones
    // -------------------------------------------------------------------------

    /**
     * Opciones para el Select del formulario.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return [
            'legal' => 'Legal (IPS, IRP)',
            'judicial' => 'Judicial (alimentaria, embargo)',
            'voluntary' => 'Voluntaria (seguros, cooperativas)',
            'loan' => 'Préstamo / Adelanto',
            'other' => 'Otros',
        ];
    }

    /**
     * Labels cortos para badges y columnas de tabla.
     *
     * @return array<string, string>
     */
    public static function getTypeLabels(): array
    {
        return [
            'legal' => 'Legal',
            'judicial' => 'Judicial',
            'voluntary' => 'Voluntaria',
            'loan' => 'Préstamo/Adelanto',
            'other' => 'Otros',
        ];
    }

    /**
     * Colores semánticos para badges de Filament.
     *
     * @return array<string, string>
     */
    public static function getTypeColors(): array
    {
        return [
            'legal' => 'danger',
            'judicial' => 'warning',
            'voluntary' => 'info',
            'loan' => 'primary',
            'other' => 'gray',
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isFixed(): bool
    {
        return $this->calculation === 'fixed';
    }

    public function isPercentage(): bool
    {
        return $this->calculation === 'percentage';
    }

    public static function formatPercent(mixed $value): ?string
    {
        return $value !== null ? number_format((float) $value, 2).'%' : null;
    }

    public function calculateAmount(int|float $salaryBase, mixed $customAmount = null): float
    {
        if ($customAmount !== null) {
            return (float) $customAmount;
        }

        if ($this->isPercentage()) {
            return round($salaryBase * ((float) $this->percent / 100), 2);
        }

        return (float) ($this->amount ?? 0);
    }
}
