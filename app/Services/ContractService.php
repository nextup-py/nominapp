<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class ContractService
{
    /**
     * Renueva un contrato creando uno nuevo.
     * Aplica Art. 53 CLT: segunda renovación de plazo fijo = contrato indefinido.
     * Asigna el usuario autenticado como creador del nuevo contrato.
     */
    public static function renew(Contract $contract, array $data): Contract
    {
        $data['created_by_id'] = Auth::id();

        return $contract->renew($data);
    }

    /**
     * Termina un contrato registrando el motivo en las notas. Con `$date` la terminación rige desde ese día (también
     * pasa a ser la fecha de fin si el contrato no tenía una anterior); con `$type` queda el tipo de baja.
     */
    public static function terminate(Contract $contract, ?string $reason, ?Carbon $date = null, ?string $type = null): void
    {
        $contract->update([
            'status' => 'terminated',
            'terminated_at' => $date ? $date->copy()->endOfDay() : now(),
            'end_date' => $date && ($contract->end_date === null || $contract->end_date->gt($date)) ? $date->toDateString() : $contract->end_date,
            'termination_type' => $type,
            'termination_reason' => $reason,
            'notes' => $contract->notes
                ? $contract->notes."\n\nTerminación: ".($reason ?? 'Sin motivo especificado')
                : 'Terminación: '.($reason ?? 'Sin motivo especificado'),
        ]);
    }
}
