<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

class EmployeeDeduction extends Pivot
{
    protected $table = 'employee_deductions';

    public $incrementing = true;

    protected $fillable = [
        'employee_id',
        'deduction_id',
        'start_date',
        'end_date',
        'custom_amount',
        'notes',
        'deactivated_by_system',
    ];

    protected $casts = [
        'start_date'            => 'date',
        'end_date'              => 'date',
        'custom_amount'         => 'decimal:2',
        'deactivated_by_system' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relaciones
    // -------------------------------------------------------------------------

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function deduction()
    {
        return $this->belongsTo(Deduction::class);
    }

    /**
     * Relación con el modelo Absence, una deducción puede originarse de una ausencia
     */
    public function absence()
    {
        return $this->hasOne(Absence::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Asignaciones activas: ya iniciadas y sin fecha de fin o con fecha de fin futura.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('start_date', '<=', now())
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    /**
     * Asignaciones inactivas: con fecha de fin ya vencida.
     */
    public function scopeInactive($query)
    {
        return $query->whereNotNull('end_date')->where('end_date', '<', now());
    }

    /**
     * Asignaciones pendientes: aún no han iniciado.
     */
    public function scopePending($query)
    {
        return $query->where('start_date', '>', now());
    }

    /**
     * Asignaciones que estuvieron activas en algún momento dentro del rango dado.
     */
    public function scopeForPeriod($query, Carbon $from, Carbon $to)
    {
        return $query
            ->where('start_date', '<=', $to)
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from));
    }

    /**
     * Scope para filtrar por empleado.
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Scope para filtrar por deducción.
     */
    public function scopeForDeduction($query, $deductionId)
    {
        return $query->where('deduction_id', $deductionId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Verificar si la asignación está activa: ya inició y sin fecha de fin o con fecha de fin futura.
     */
    public function isActive(): bool
    {
        return $this->start_date->lte(now())
            && (is_null($this->end_date) || $this->end_date->gte(now()));
    }

    /**
     * Verificar si tiene un monto personalizado definido.
     */
    public function hasCustomAmount(): bool
    {
        return ! is_null($this->custom_amount);
    }

    /**
     * Marcar la asignación como inactiva estableciendo la fecha de fin.
     */
    public function deactivate(?Carbon $endDate = null, bool $bySystem = false): bool
    {
        return $this->update([
            'end_date'              => $endDate ?? now(),
            'deactivated_by_system' => $bySystem,
        ]);
    }

    /**
     * Reactivar una asignación con una nueva fecha de inicio y opcionalmente una fecha de fin.
     */
    public function reactivate(?Carbon $startDate = null, ?Carbon $endDate = null): bool
    {
        return $this->update([
            'start_date' => $startDate ?? now(),
            'end_date'   => $endDate,
        ]);
    }
}
