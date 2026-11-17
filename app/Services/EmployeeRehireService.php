<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Employee;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reingreso de un empleado dado de baja: lo reactiva y le abre un contrato nuevo prellenado con los datos del último.
 * No restaura percepciones ni deducciones (la baja las cerró): se asignan de nuevo. La antigüedad solo se acumula si
 * RR.HH. lo decide en cada reingreso (`seniority_from` = inicio del primer contrato); si corresponde acumular es
 * criterio legal que confirma el asesor, no una regla del sistema.
 */
class EmployeeRehireService
{
    /** Último contrato del empleado (el de mayor `start_date`), base del prellenado. */
    public static function lastContract(Employee $employee): ?Contract
    {
        return $employee->contracts()->latest('start_date')->latest('id')->first();
    }

    /** Inicio del primer contrato del empleado: desde ahí corre la antigüedad si se acumula. */
    public static function firstStartDate(Employee $employee): ?Carbon
    {
        return $employee->contracts()->oldest('start_date')->first()?->start_date;
    }

    /**
     * Reactiva al empleado y crea el contrato nuevo. `$data`: `type`, `start_date`, `end_date`, `trial_days`,
     * `salary`, `accumulate_seniority` y opcionalmente `notes`; el resto se copia del último contrato.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DomainException Si el empleado ya está activo o tiene contrato vigente, o los datos incumplen el Art. 53.
     */
    public function rehire(Employee $employee, array $data): Contract
    {
        if ($employee->status === 'active') {
            throw new DomainException('El empleado ya está activo.');
        }

        if ($employee->contracts()->whereIn('status', ['active', 'suspended'])->exists()) {
            throw new DomainException('El empleado ya tiene un contrato vigente.');
        }

        $last = self::lastContract($employee);

        if (! $last) {
            throw new DomainException('El empleado no tiene contratos anteriores: cargá un contrato nuevo desde Contratos.');
        }

        $type = $data['type'] ?? $last->type;
        $start = Carbon::parse($data['start_date']);
        $end = Contract::requiresEndDate($type) && filled($data['end_date'] ?? null) ? Carbon::parse($data['end_date']) : null;

        if (Contract::requiresEndDate($type) && ! $end) {
            throw new DomainException('Este tipo de contrato exige fecha de finalización.');
        }

        if ($end && $end->lte($start)) {
            throw new DomainException('La fecha de finalización debe ser posterior a la de inicio.');
        }

        if ($end && in_array($type, ['plazo_fijo', 'aprendizaje'], true) && $end->gt($start->copy()->addYear())) {
            throw new DomainException('Art. 53 CLT: los contratos a plazo determinado no pueden exceder 1 año.');
        }

        return DB::transaction(function () use ($employee, $data, $last, $type, $start, $end): Contract {
            $contract = Contract::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'start_date' => $start->toDateString(),
                'seniority_from' => ($data['accumulate_seniority'] ?? false) ? self::firstStartDate($employee)?->toDateString() : null,
                'end_date' => $end?->toDateString(),
                'trial_days' => (int) ($data['trial_days'] ?? 0) ?: null,
                'salary_type' => $last->salary_type,
                'salary' => $data['salary'] ?? $last->salary,
                'payroll_type' => $last->payroll_type,
                'position_id' => $last->position_id,
                'department_id' => $last->department_id,
                'work_modality' => $last->work_modality,
                'payment_method' => $last->payment_method,
                'advance_percent' => $last->advance_percent,
                'status' => 'active',
                'notes' => trim('Reingreso. '.($data['notes'] ?? '')),
                'created_by_id' => Auth::id(),
            ]);

            $employee->update(['status' => 'active']);

            return $contract;
        });
    }
}
