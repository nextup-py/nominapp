<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * Detecta empleados que deberían estar trabajando y no tienen ninguna marcación en los últimos días: sin celular
 * vinculado ni terminal a mano, sin horario o con el dispositivo roto. Es el aviso temprano que evita que se
 * acumulen cientos de ausencias pendientes de unas pocas personas.
 */
class SilentEmployeesService
{
    /** Días sin marcar por defecto para considerar a alguien «en silencio». */
    public const DEFAULT_DAYS = 7;

    /**
     * Empleados activos con contrato vigente (no suspendido), con al menos `$days` días de antigüedad en él, sin
     * ninguna marcación en ese lapso y sin vacaciones ni licencias aprobadas que lo expliquen.
     *
     * @return Collection<int, Employee>
     */
    public function find(int $days = self::DEFAULT_DAYS): Collection
    {
        $since = now()->subDays($days);
        $from = $since->toDateString();
        $today = today()->toDateString();

        return Employee::query()
            ->active()
            ->whereHas('activeContract', fn ($q) => $q->where('status', 'active')->where('start_date', '<=', $from))
            ->whereDoesntHave('attendanceEvents', fn ($q) => $q->where('attendance_events.recorded_at', '>=', $since))
            ->whereDoesntHave('vacations', fn ($q) => $q->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $from))
            ->whereDoesntHave('leaves', fn ($q) => $q->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $from))
            ->with(['branch', 'activeContract.position'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }
}
