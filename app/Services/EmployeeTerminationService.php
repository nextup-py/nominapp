<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Loan;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Baja guiada de un empleado: termina su contrato vigente (con fecha, tipo y motivo), lo deja inactivo y deja los
 * pendientes a la vista. No toca la liquidación: si se quiere liquidar en el sistema, la liquidación se crea ANTES de
 * terminar el contrato (crearla exige un contrato activo) y su cierre termina el contrato por su cuenta.
 */
class EmployeeTerminationService
{
    /**
     * Pendientes del empleado que la baja no resuelve sola, para mostrarlos en el modal.
     *
     * @return array{loans: int, advances: int}
     */
    public static function pendingObligations(Employee $employee): array
    {
        return [
            'loans' => Loan::query()->where('employee_id', $employee->id)->where('status', 'approved')->count(),
            'advances' => Advance::query()->where('employee_id', $employee->id)->where('status', 'disbursed')->whereNull('payroll_id')->count(),
        ];
    }

    /**
     * Da de baja al empleado. Devuelve el contrato terminado (null si no tenía contrato vigente).
     *
     * @throws DomainException Si el empleado ya está inactivo, la fecha es futura o anterior al inicio del contrato.
     */
    public function terminate(Employee $employee, Carbon $date, string $type, ?string $reason = null): ?Contract
    {
        if ($employee->status !== 'active') {
            throw new DomainException('El empleado ya está inactivo.');
        }

        if ($date->gt(today())) {
            throw new DomainException('La fecha de baja no puede ser futura.');
        }

        $contract = $employee->activeContract;

        if ($contract && $date->lt($contract->start_date)) {
            throw new DomainException('La fecha de baja no puede ser anterior al inicio del contrato ('.$contract->start_date->format('d/m/Y').').');
        }

        return DB::transaction(function () use ($employee, $date, $type, $reason, $contract): ?Contract {
            if ($contract) {
                ContractService::terminate($contract, $reason, $date, $type);
            }

            $employee->refresh();

            if ($employee->status !== 'inactive') {
                $employee->update(['status' => 'inactive']);
            }

            return $contract;
        });
    }
}
