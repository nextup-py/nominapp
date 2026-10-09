<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Arma el organigrama de una empresa: árbol de cargos por departamento con sus empleados (activos y
 * suspendidos), vacantes (cargos sin ocupante) y filtros por sucursal, departamento y búsqueda.
 *
 * Hace una consulta de empleados y una de cargos, y resuelve ancestros en memoria (sin N+1) y con
 * protección contra ciclos de `parent_id`.
 */
class OrgChartService
{
    /** Estado de empleado que aparece en el organigrama (la suspensión vive en el estado del contrato, no del empleado). */
    public const VISIBLE_STATUS = 'active';

    /**
     * Construye el organigrama.
     *
     * @param  array{branch?: ?int, department?: ?int, search?: ?string, vacancies?: bool}  $filters
     * @return array{tree: array<int, array<string, mixed>>, unassigned: array<int, array<string, mixed>>, stats: array<string, int>, showBranch: bool}
     */
    public function build(Company $company, array $filters = []): array
    {
        $branchId = $filters['branch'] ?? null;
        $departmentId = $filters['department'] ?? null;
        $search = filled($filters['search'] ?? null) ? mb_strtolower(trim($filters['search'])) : null;
        $withVacancies = $filters['vacancies'] ?? true;

        $employees = Employee::query()
            ->whereIn('branch_id', $company->branches()->select('id'))
            ->where('status', self::VISIBLE_STATUS)
            ->with(['branch:id,name', 'activeContract:id,employee_id,position_id,start_date,status'])
            ->get();

        $departmentIds = $company->departments()->pluck('id');
        $positionIdsInUse = $employees->pluck('activeContract.position_id')->filter()->unique();

        /** @var Collection<int, Position> $positions */
        $positions = Position::query()
            ->with('department:id,name')
            ->where(fn ($query) => $query->whereIn('department_id', $departmentIds)->orWhereIn('id', $positionIdsInUse))
            ->get()
            ->keyBy('id');

        $occupiedIds = $positionIdsInUse->flip();

        $entries = $employees
            ->map(fn (Employee $employee) => $this->employeeEntry($employee))
            ->groupBy(fn (array $entry) => $positions->has($entry['position_id']) ? $entry['position_id'] : 0);

        $visibleEmployees = fn (int $positionId): Collection => ($entries[$positionId] ?? collect())
            ->filter(fn (array $entry) => $branchId === null || $entry['branch_id'] === $branchId);

        $kept = $positions->keys()->filter(function (int $id) use ($positions, $departmentId, $search, $withVacancies, $occupiedIds, $visibleEmployees) {
            $position = $positions[$id];

            if ($departmentId !== null && $position->department_id !== $departmentId) {
                return false;
            }

            $nameMatches = $search !== null && str_contains(mb_strtolower($position->name), $search);

            if ($search !== null && $nameMatches) {
                return true;
            }

            $hasEmployees = $this->filterEmployees($visibleEmployees($id), $search, false)->isNotEmpty();

            return $hasEmployees || ($search === null && $withVacancies && ! $occupiedIds->has($id));
        })->values()->all();

        $kept = $this->withAncestors($kept, $positions, $departmentId);

        $nodeEmployees = [];
        foreach ($kept as $id) {
            $nameMatches = $search !== null && str_contains(mb_strtolower($positions[$id]->name), $search);
            $nodeEmployees[$id] = $this->filterEmployees($visibleEmployees($id), $search, $nameMatches)->values()->all();
        }

        $tree = $this->buildTree($positions, $kept, $nodeEmployees, $occupiedIds->keys()->all());

        $unassigned = $departmentId === null
            ? $this->filterEmployees(($entries[0] ?? collect())->filter(fn (array $entry) => $branchId === null || $entry['branch_id'] === $branchId), $search, false)->values()->all()
            : [];

        return [
            'tree' => $tree,
            'unassigned' => $unassigned,
            'stats' => $this->stats($tree, $unassigned),
            'showBranch' => $company->branches()->count() > 1,
        ];
    }

    /**
     * Datos de un empleado para las tarjetas del organigrama.
     *
     * @return array<string, mixed>
     */
    private function employeeEntry(Employee $employee): array
    {
        $since = $employee->activeContract?->start_date;

        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'photo' => $employee->photo ? asset('storage/'.$employee->photo) : null,
            'suspended' => $employee->activeContract?->status === 'suspended',
            'since' => $since?->format('m/Y'),
            'branch' => $employee->branch?->name,
            'branch_id' => $employee->branch_id,
            'position_id' => $employee->activeContract?->position_id ?? 0,
        ];
    }

    /**
     * Con la búsqueda activa deja solo los empleados que coinciden por nombre, salvo que coincida el nombre del cargo.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function filterEmployees(Collection $entries, ?string $search, bool $positionNameMatches): Collection
    {
        if ($search === null || $positionNameMatches) {
            return $entries;
        }

        return $entries->filter(fn (array $entry) => str_contains(mb_strtolower($entry['name']), $search));
    }

    /**
     * Agrega a los cargos mantenidos sus ancestros (en memoria y sin recorrer ciclos), para que el árbol no quede cortado.
     *
     * @param  array<int, int>  $ids
     * @param  Collection<int, Position>  $positions
     * @return array<int, int>
     */
    private function withAncestors(array $ids, Collection $positions, ?int $departmentId): array
    {
        $all = array_flip($ids);

        foreach ($ids as $id) {
            $seen = [$id => true];
            $current = $positions[$id] ?? null;

            while ($current && $current->parent_id && ! isset($seen[$current->parent_id])) {
                $parent = $positions[$current->parent_id] ?? null;

                if ($parent === null || ($departmentId !== null && $parent->department_id !== $departmentId)) {
                    break;
                }

                $seen[$parent->id] = true;
                $all[$parent->id] = true;
                $current = $parent;
            }
        }

        return array_keys($all);
    }

    /**
     * Arma el árbol agrupado por departamento. Los cargos cuyo padre no está en su departamento (o que
     * quedan en un ciclo de `parent_id`) se muestran como raíces, para que nunca se pierdan en silencio.
     *
     * @param  Collection<int, Position>  $positions
     * @param  array<int, int>  $kept
     * @param  array<int, array<int, array<string, mixed>>>  $nodeEmployees
     * @param  array<int, int>  $occupiedIds
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(Collection $positions, array $kept, array $nodeEmployees, array $occupiedIds): array
    {
        $byDepartment = collect($kept)->map(fn (int $id) => $positions[$id])->groupBy('department_id');
        $tree = [];

        foreach ($byDepartment as $departmentPositions) {
            $ids = $departmentPositions->pluck('id')->all();
            $visited = [];

            $roots = $departmentPositions
                ->filter(fn (Position $position) => $position->parent_id === null || ! in_array($position->parent_id, $ids, true))
                ->all();

            $build = function (Position $position) use (&$build, $departmentPositions, &$visited, $nodeEmployees, $occupiedIds): ?array {
                if (isset($visited[$position->id])) {
                    return null;
                }
                $visited[$position->id] = true;

                $children = $departmentPositions
                    ->filter(fn (Position $child) => $child->parent_id === $position->id)
                    ->map(fn (Position $child) => $build($child))
                    ->filter()
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();

                return [
                    'id' => $position->id,
                    'name' => $position->name,
                    'department' => $position->department?->name ?? 'Sin Departamento',
                    'employees' => $nodeEmployees[$position->id] ?? [],
                    'vacant' => ! in_array($position->id, $occupiedIds, true),
                    'children' => $children,
                ];
            };

            $nodes = collect($roots)->map(fn (Position $position) => $build($position))->filter();

            foreach ($departmentPositions as $position) {
                if (! isset($visited[$position->id])) {
                    $nodes->push($build($position));
                }
            }

            $tree[] = [
                'name' => $departmentPositions->first()->department?->name ?? 'Sin Departamento',
                'positions' => $nodes->filter()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ];
        }

        usort($tree, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $tree;
    }

    /**
     * Totales del encabezado, calculados sobre lo que realmente se muestra.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @param  array<int, array<string, mixed>>  $unassigned
     * @return array{departments: int, positions: int, employees: int, vacancies: int}
     */
    private function stats(array $tree, array $unassigned): array
    {
        $positions = 0;
        $employees = count($unassigned);
        $vacancies = 0;

        $walk = function (array $nodes) use (&$walk, &$positions, &$employees, &$vacancies): void {
            foreach ($nodes as $node) {
                $positions++;
                $employees += count($node['employees']);
                $vacancies += $node['vacant'] ? 1 : 0;
                $walk($node['children']);
            }
        };

        foreach ($tree as $department) {
            $walk($department['positions']);
        }

        return ['departments' => count($tree), 'positions' => $positions, 'employees' => $employees, 'vacancies' => $vacancies];
    }
}
