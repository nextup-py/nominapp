<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Contract;
use App\Models\Liquidacion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Detecta contratos que quedaron «Vigentes» aunque el empleado ya no trabaja (bajas hechas cambiando el estado del
 * empleado a mano, sin pasar por Terminar contrato ni Liquidación) y arma la reparación.
 */
class ContractConsistencyService
{
    public const CASE_PENDING_LIQUIDATION = 'pending_liquidation';

    public const CASE_NO_LIQUIDATION = 'no_liquidation';

    public const CASE_ORPHAN = 'orphan';

    /**
     * Contratos vigentes o suspendidos cuyo empleado está inactivo o no existe, clasificados por caso.
     *
     * @return Collection<int, array{contract: Contract, case: string, last_attendance: ?Carbon}>
     */
    public function staleContracts(): Collection
    {
        $contracts = Contract::query()
            ->with('employee')
            ->whereIn('status', ['active', 'suspended'])
            ->where(fn ($q) => $q->whereDoesntHave('employee')->orWhereHas('employee', fn ($e) => $e->where('status', 'inactive')))
            ->orderBy('employee_id')
            ->get();

        $employeeIds = $contracts->pluck('employee_id')->unique();
        $lastAttendance = AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->selectRaw('employee_id, MAX(date) as last_date')
            ->groupBy('employee_id')
            ->pluck('last_date', 'employee_id');
        $open = Liquidacion::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['draft', 'calculated'])
            ->pluck('employee_id')
            ->flip();

        return $contracts->map(fn (Contract $contract): array => [
            'contract' => $contract,
            'case' => match (true) {
                $contract->employee === null => self::CASE_ORPHAN,
                $open->has($contract->employee_id) => self::CASE_PENDING_LIQUIDATION,
                default => self::CASE_NO_LIQUIDATION,
            },
            'last_attendance' => isset($lastAttendance[$contract->employee_id]) ? Carbon::parse($lastAttendance[$contract->employee_id]) : null,
        ]);
    }

    /**
     * Termina el contrato de un empleado inactivo sin liquidación abierta. La fecha de baja es la última asistencia
     * (o hoy si nunca marcó) y queda constancia en las notas.
     */
    public function close(Contract $contract, ?Carbon $lastAttendance): void
    {
        $date = $lastAttendance ?? today();
        $note = 'Terminación: baja del empleado sin contrato cerrado; regularizado el '.today()->format('d/m/Y')
            .' con fecha de baja '.$date->format('d/m/Y').($lastAttendance ? ' (última asistencia)' : '').'.';

        $contract->update([
            'status' => 'terminated',
            'terminated_at' => $date->copy()->endOfDay(),
            'notes' => $contract->notes ? $contract->notes."\n\n".$note : $note,
        ]);
    }
}
