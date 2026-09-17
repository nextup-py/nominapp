<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Terminal;
use Carbon\Carbon;

/**
 * Sincronización incremental (delta) de descriptores faciales de empleados
 * activos, scopeada por la sucursal del terminal — alimenta la caché offline
 * del terminal (IndexedDB) para que el reconocimiento facial pueda correr
 * localmente sin conexión.
 */
class EmployeeDescriptorSyncService
{
    /**
     * @return array{
     *     employees: array<int, array{id: int, first_name: string, last_name: string, ci: string|null, face_descriptor: array, photo_thumbnail: string|null}>,
     *     tombstones: array<int, int>,
     *     break_flags: array<int, bool>,
     *     server_time: string,
     *     sync_version: string,
     * }
     */
    public function deltaSince(Terminal $terminal, ?Carbon $since): array
    {
        // Se toma antes de consultar para que el próximo `since` del cliente nunca
        // quede por delante de datos que todavía no vio (mejor repetir de más que perder cambios).
        $queryStartedAt = now();

        $employees = Employee::query()
            ->where('branch_id', $terminal->branch_id)
            ->where('status', 'active')
            ->whereNotNull('face_descriptor')
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->select(['id', 'first_name', 'last_name', 'ci', 'face_descriptor', 'photo_thumbnail'])
            ->get();

        return [
            // `photo_thumbnail` viaja como data URI ya pre-generado (ver
            // EmployeePhotoThumbnailService / EmployeeObserver) — la foto
            // original (potencialmente varios MB) nunca se sincroniza.
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'ci' => $employee->ci,
                'face_descriptor' => $employee->face_descriptor,
                'photo_thumbnail' => $employee->photo_thumbnail,
            ])->values()->all(),
            'tombstones' => $since ? $this->tombstonesSince($terminal, $since) : [],
            'break_flags' => $this->breakFlagsForBranch($terminal),
            'server_time' => now()->toIso8601String(),
            'sync_version' => $queryStartedAt->toIso8601String(),
        ];
    }

    /**
     * IDs de empleados de esta sucursal que cambiaron desde `$since` y ya no
     * califican para la caché offline (se desactivaron o perdieron su
     * descriptor) — el terminal debe eliminarlos de su copia local.
     *
     * Limitación conocida: si un empleado se movió a OTRA sucursal, esta
     * consulta no lo detecta porque ya no aparece con branch_id = la
     * sucursal del terminal. Un cambio de sucursal es infrecuente y, en el
     * peor caso, deja un descriptor obsoleto en la caché del terminal viejo
     * hasta la próxima re-provisión — no un problema de seguridad (el
     * descriptor ya era visible para ese terminal), solo de limpieza.
     *
     * @return array<int, int>
     */
    private function tombstonesSince(Terminal $terminal, Carbon $since): array
    {
        return Employee::query()
            ->where('branch_id', $terminal->branch_id)
            ->where('updated_at', '>', $since)
            ->where(function ($query) {
                $query->where('status', '!=', 'active')->orWhereNull('face_descriptor');
            })
            ->pluck('id')
            ->all();
    }

    /**
     * "¿Tiene descanso hoy?" para cada empleado activo de la sucursal — se
     * recalcula completo en CADA sincronización (no solo para los que
     * cambiaron desde `$since`), porque el horario de un empleado puede
     * cambiar sin tocar el registro de `Employee` (ej. una nueva rotación
     * asignada), y además el día calendario avanza. Alimenta el filtro de
     * "Inicio de descanso" en la resolución local offline (ver
     * terminal-offline/queue.js, `allowedNextEventTypes()`); mientras el
     * terminal tiene red se refresca cada ciclo de sync de empleados
     * (~5 min), así que solo puede quedar desactualizado durante un corte
     * de conexión más largo que eso.
     *
     * @return array<int, bool>
     */
    private function breakFlagsForBranch(Terminal $terminal): array
    {
        $today = now(config('app.timezone'));

        $employees = Employee::query()
            ->where('branch_id', $terminal->branch_id)
            ->where('status', 'active')
            ->whereNotNull('face_descriptor')
            ->get(['id', 'schedule_id']);

        return AttendanceCalculator::hasScheduledBreakBatch($employees, $today);
    }
}
