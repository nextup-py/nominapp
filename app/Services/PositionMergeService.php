<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona dos cargos duplicados (por ejemplo «Supervisor De Sucursal» y «Supervisor de Sucursal»): los contratos, los
 * cargos que reportaban al sobrante y las sucursales extra de marcación pasan al cargo que se conserva, y el sobrante
 * se elimina. Solo entre cargos de la misma empresa.
 */
class PositionMergeService
{
    /**
     * Qué se movería al fusionar `$from` en `$into`, y el error si no se puede.
     *
     * @return array{error: string|null, contracts: int, active_employees: int, children: int, differences: array<int, string>}
     */
    public function preview(Position $from, Position $into): array
    {
        $from->loadMissing('department');
        $into->loadMissing('department');

        return [
            'error' => $this->error($from, $into),
            'contracts' => Contract::query()->where('position_id', $from->id)->count(),
            'active_employees' => $from->employees()->count(),
            'children' => Position::query()->where('parent_id', $from->id)->where('id', '!=', $into->id)->count(),
            'differences' => $this->differences($from, $into),
        ];
    }

    /**
     * Fusiona `$from` en `$into` y elimina `$from`.
     *
     * @return array{contracts: int, children: int}
     *
     * @throws \InvalidArgumentException Si no se pueden fusionar.
     */
    public function merge(Position $from, Position $into): array
    {
        $from->loadMissing('department');
        $into->loadMissing('department');

        if (($error = $this->error($from, $into)) !== null) {
            throw new \InvalidArgumentException($error);
        }

        $result = DB::transaction(function () use ($from, $into): array {
            $contracts = Contract::query()
                ->where('position_id', $from->id)
                ->update(['position_id' => $into->id, 'department_id' => $into->department_id]);

            // Si el cargo que se conserva reportaba al sobrante, pasa a reportar a donde reportaba este.
            if ($into->parent_id === $from->id) {
                $into->update(['parent_id' => $from->parent_id]);
            }

            $children = Position::query()
                ->where('parent_id', $from->id)
                ->where('id', '!=', $into->id)
                ->update(['parent_id' => $into->id]);

            $into->terminalBranches()->syncWithoutDetaching($from->terminalBranches()->pluck('branches.id')->all());

            $from->delete();

            return ['contracts' => $contracts, 'children' => $children];
        });

        $into->touchEmployees();

        return $result;
    }

    /**
     * Motivo por el que no se pueden fusionar, o null si se puede.
     */
    private function error(Position $from, Position $into): ?string
    {
        if ($from->is($into)) {
            return 'Hay que elegir otro cargo: no se puede fusionar un cargo consigo mismo.';
        }

        if ($from->department?->company_id !== $into->department?->company_id) {
            return 'Los dos cargos tienen que ser de la misma empresa.';
        }

        return null;
    }

    /**
     * Configuraciones que difieren entre los dos cargos: al fusionar prevalece la del cargo que se conserva.
     *
     * @return array<int, string>
     */
    private function differences(Position $from, Position $into): array
    {
        $differences = [];

        if ($from->department_id !== $into->department_id) {
            $differences[] = 'Están en departamentos distintos: los contratos pasan al departamento de «'.$into->name.'».';
        }

        if ($from->terminal_access !== $into->terminal_access) {
            $differences[] = 'Difiere dónde pueden marcar en terminal: prevalece lo de «'.$into->name.'».';
        }

        if ($from->mobile_marking_allowed !== $into->mobile_marking_allowed) {
            $differences[] = 'Difiere el permiso de marcar desde el celular: prevalece lo de «'.$into->name.'».';
        }

        if ((bool) $from->marks_breaks !== (bool) $into->marks_breaks) {
            $differences[] = 'Difiere si marcan descanso: prevalece lo de «'.$into->name.'».';
        }

        return $differences;
    }
}
