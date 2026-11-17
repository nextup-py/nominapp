<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;

/**
 * Define a quién ve cada usuario en «Mi equipo»: con `view_team`, a quienes le reportan directa o indirectamente
 * (`employees.reports_to_id`); con `view_branch_team`, además a todo el personal activo de su sucursal. Parte de la
 * ficha vinculada al usuario (`users.employee_id`): sin ella no hay equipo. Nunca incluye al propio usuario.
 */
class TeamScopeService
{
    /** Tope de niveles al bajar por la cadena de superiores (defensa ante ciclos). */
    private const MAX_DEPTH = 20;

    /** Si el usuario puede abrir «Mi equipo» (alguno de los dos permisos). */
    public function canView(User $user): bool
    {
        return $user->can('view_team') || $user->can('view_branch_team');
    }

    /** Si hay una ficha vinculada desde la que calcular el equipo. */
    public function hasLinkedEmployee(User $user): bool
    {
        return $user->employee_id !== null && Employee::query()->whereKey($user->employee_id)->exists();
    }

    /**
     * Ids de los empleados activos que el usuario puede ver.
     *
     * @return array<int, int>
     */
    public function employeeIds(User $user): array
    {
        if (! $this->hasLinkedEmployee($user)) {
            return [];
        }

        $ids = [];

        if ($user->can('view_team')) {
            $ids = $this->descendantIds((int) $user->employee_id);
        }

        if ($user->can('view_branch_team')) {
            $branchId = Employee::query()->whereKey($user->employee_id)->value('branch_id');
            $branchIds = Employee::query()->active()->where('branch_id', $branchId)->pluck('id')->all();
            $ids = [...$ids, ...$branchIds];
        }

        return array_values(array_diff(array_unique(array_map('intval', $ids)), [(int) $user->employee_id]));
    }

    /**
     * Subordinados directos e indirectos de un empleado, nivel por nivel.
     *
     * @return array<int, int>
     */
    private function descendantIds(int $employeeId): array
    {
        $found = [];
        $level = [$employeeId];

        for ($depth = 0; $depth < self::MAX_DEPTH && $level !== []; $depth++) {
            $next = Employee::query()->whereIn('reports_to_id', $level)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $next = array_values(array_diff($next, $found, [$employeeId]));
            $found = [...$found, ...$next];
            $level = $next;
        }

        return $found;
    }
}
