<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Día laborable efectivamente suspendido por una amonestación disciplinaria. */
class WarningSuspensionDay extends Model
{
    protected $fillable = [
        'warning_id',
        'employee_id',
        'date',
        'employee_deduction_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /** Amonestación que originó la suspensión. */
    public function warning(): BelongsTo
    {
        return $this->belongsTo(Warning::class);
    }

    /** Empleado suspendido. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Deducción SUS-DIS generada para este día. */
    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class);
    }
}
