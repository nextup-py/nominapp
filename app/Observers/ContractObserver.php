<?php

namespace App\Observers;

use App\Models\Contract;

/**
 * Sincroniza el estado del empleado y sus percepciones/deducciones
 * cuando el estado del contrato cambia.
 */
class ContractObserver
{
    public function updated(Contract $contract): void
    {
        if (! $contract->isDirty('status')) {
            return;
        }

        $employee = $contract->employee;

        if ($contract->status === 'suspended') {
            $employee->activeEmployeePerceptions->each(fn ($p) => $p->deactivate(bySystem: true));
            $employee->activeEmployeeDeductions->each(fn ($d) => $d->deactivate(bySystem: true));
        } elseif ($contract->status === 'active' && $contract->getOriginal('status') === 'suspended') {
            $employee->employeePerceptions()
                ->where('deactivated_by_system', true)
                ->get()
                ->each(fn ($p) => $p->update(['end_date' => null, 'deactivated_by_system' => false]));

            $employee->employeeDeductions()
                ->where('deactivated_by_system', true)
                ->get()
                ->each(fn ($d) => $d->update(['end_date' => null, 'deactivated_by_system' => false]));
        } elseif ($contract->status === 'active' && $contract->getOriginal('status') === 'expired') {
            // El contrato vencido volvió a estar vigente (fecha de fin extendida): el empleado deja de estar inactivo por ello
            if ($employee->status === 'inactive') {
                $employee->update(['status' => 'active']);
            }
        } elseif (in_array($contract->status, ['expired', 'terminated'])) {
            // Solo marcar inactivo si no tiene otro contrato vigente
            $hasOtherActive = $employee->contracts()
                ->where('id', '!=', $contract->id)
                ->where('status', 'active')
                ->exists();

            if (! $hasOtherActive) {
                $employee->update(['status' => 'inactive']);
            }
        }
    }
}
