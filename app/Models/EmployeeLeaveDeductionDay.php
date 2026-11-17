<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Día laborable descontado por una licencia de día completo con descuento (una deducción LIC001 por día). */
class EmployeeLeaveDeductionDay extends Model
{
    protected $fillable = ['employee_leave_id', 'date', 'employee_deduction_id'];

    protected $casts = ['date' => 'date'];

    /** Licencia que originó el descuento. */
    public function leave(): BelongsTo
    {
        return $this->belongsTo(EmployeeLeave::class, 'employee_leave_id');
    }

    /** Deducción LIC001 generada para este día. */
    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class);
    }
}
