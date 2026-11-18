<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Línea de reporte de los empleados («Reporta a», `employees.reports_to_id`): valida que un superior sea posible
 * (activo, de la misma empresa, sin ciclos), lo asigna de a uno o en bloque y arma reglas con vista previa
 * (por ejemplo «todos los de la sucursal X reportan a su Encargada»).
 */
class ReportingLineService
{
    public const ERROR_SELF = 'self';

    public const ERROR_INACTIVE = 'inactive';

    public const ERROR_OTHER_COMPANY = 'other_company';

    public const ERROR_CYCLE = 'cycle';

    public const SKIP_ALREADY = 'already';

    public const SKIP_NO_BOSS = 'no_boss';

    public const SKIP_MANY_BOSSES = 'many_bosses';

    /** Tope de saltos al subir por la cadena de superiores (protege de datos que ya tengan un ciclo). */
    private const MAX_DEPTH = 50;

    /**
     * Motivos por los que un empleado no puede reportar a otro, o por los que una regla lo omite.
     *
     * @return array<string, string>
     */
    public static function getReasonLabels(): array
    {
        return [
            self::ERROR_SELF => 'No puede reportarse a sí mismo',
            self::ERROR_INACTIVE => 'El superior no está activo',
            self::ERROR_OTHER_COMPANY => 'El superior es de otra empresa',
            self::ERROR_CYCLE => 'Crearía un ciclo (el superior ya reporta, directa o indirectamente, a este empleado)',
            self::SKIP_ALREADY => 'Ya tiene superior',
            self::SKIP_NO_BOSS => 'No hay nadie con ese cargo en su sucursal',
            self::SKIP_MANY_BOSSES => 'Hay más de uno con ese cargo en su sucursal',
        ];
    }

    /**
     * Valida que `$manager` pueda ser el superior de `$employee`. Devuelve la clave del error o null si es válido.
     */
    public function validate(Employee $employee, Employee $manager): ?string
    {
        if ($employee->is($manager)) {
            return self::ERROR_SELF;
        }

        if ($manager->status !== 'active') {
            return self::ERROR_INACTIVE;
        }

        if ($manager->branch?->company_id !== $employee->branch?->company_id) {
            return self::ERROR_OTHER_COMPANY;
        }

        return $this->createsCycle($employee, $manager) ? self::ERROR_CYCLE : null;
    }

    /**
     * ¿Asignar a `$manager` como superior de `$employee` cerraría un ciclo? Sube por los superiores del candidato
     * y busca al empleado.
     */
    public function createsCycle(Employee $employee, Employee $manager): bool
    {
        $currentId = $manager->reports_to_id;
        $steps = 0;

        while ($currentId !== null && $steps++ < self::MAX_DEPTH) {
            if ((int) $currentId === $employee->id) {
                return true;
            }

            $currentId = Employee::query()->whereKey($currentId)->value('reports_to_id');
        }

        return false;
    }

    /**
     * Ids de todos los subordinados de un empleado, directos e indirectos (candidatos que cerrarían un ciclo
     * como superior suyo). Recorre por niveles con el mismo tope de profundidad que `createsCycle()`.
     *
     * @return array<int, int>
     */
    public function descendantIds(Employee $employee): array
    {
        $found = [];
        $level = [$employee->id];

        for ($depth = 0; $depth < self::MAX_DEPTH && $level !== []; $depth++) {
            $level = Employee::query()
                ->whereIn('reports_to_id', $level)
                ->whereNotIn('id', [...$found, $employee->id])
                ->pluck('id')
                ->all();

            $found = [...$found, ...$level];
        }

        return $found;
    }

    /**
     * Asigna (o quita, con null) el superior de un empleado. Devuelve la clave del error, o null si se guardó.
     */
    public function assign(Employee $employee, ?Employee $manager): ?string
    {
        if ($manager !== null && ($error = $this->validate($employee, $manager)) !== null) {
            return $error;
        }

        if ($employee->reports_to_id !== $manager?->id) {
            $employee->update(['reports_to_id' => $manager?->id]);
        }

        return null;
    }

    /**
     * Asigna el mismo superior a varios empleados y cuenta los que se omiten por motivo.
     *
     * @param  iterable<Employee>  $employees
     * @return array{assigned: int, skipped: array<string, int>}
     */
    public function assignMany(iterable $employees, Employee $manager): array
    {
        $assigned = 0;
        $skipped = [];

        foreach ($employees as $employee) {
            $error = $this->assign($employee, $manager);

            if ($error === null) {
                $assigned++;
            } else {
                $skipped[$error] = ($skipped[$error] ?? 0) + 1;
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Vista previa de una regla: a quién se le asignaría cada empleado alcanzado y por qué se omite el resto.
     *
     * Reglas (`$rule`): `branch_id` (opcional: solo esa sucursal), `position_ids` (opcional: solo quienes tienen alguno
     * de esos cargos), `manager_id` (superior fijo) o `manager_position_id` (el único activo con ese cargo en la
     * sucursal de cada empleado) y `only_without_manager` (true por defecto: respeta a quienes ya tienen superior).
     *
     * @param  array<string, mixed>  $rule
     * @return Collection<int, array{employee: Employee, manager: Employee|null, skip: string|null}>
     */
    public function preview(array $rule): Collection
    {
        $onlyWithout = (bool) ($rule['only_without_manager'] ?? true);
        $positionIds = array_filter((array) ($rule['position_ids'] ?? []));
        $fixedManager = filled($rule['manager_id'] ?? null) ? Employee::query()->with('branch')->find($rule['manager_id']) : null;
        $managerPositionId = filled($rule['manager_position_id'] ?? null) ? (int) $rule['manager_position_id'] : null;

        $holders = $managerPositionId !== null ? $this->holdersByBranch($managerPositionId) : collect();

        return Employee::query()
            ->with(['branch', 'activeContract.position'])
            ->where('status', 'active')
            ->when(filled($rule['branch_id'] ?? null), fn ($query) => $query->where('branch_id', (int) $rule['branch_id']))
            ->when($positionIds !== [], fn ($query) => $query->whereHas('activeContract', fn ($contract) => $contract->whereIn('position_id', $positionIds)))
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($onlyWithout, $fixedManager, $managerPositionId, $holders): array {
                if ($onlyWithout && $employee->reports_to_id !== null) {
                    return ['employee' => $employee, 'manager' => null, 'skip' => self::SKIP_ALREADY];
                }

                if ($managerPositionId !== null) {
                    $candidates = $holders->get($employee->branch_id, collect());

                    if ($candidates->isEmpty()) {
                        return ['employee' => $employee, 'manager' => null, 'skip' => self::SKIP_NO_BOSS];
                    }

                    if ($candidates->count() > 1) {
                        return ['employee' => $employee, 'manager' => null, 'skip' => self::SKIP_MANY_BOSSES];
                    }

                    $manager = $candidates->first();
                } else {
                    $manager = $fixedManager;
                }

                $error = $manager ? $this->validate($employee, $manager) : self::SKIP_NO_BOSS;

                return ['employee' => $employee, 'manager' => $error === null ? $manager : null, 'skip' => $error];
            })
            ->values();
    }

    /**
     * Aplica una regla: asigna a quienes la vista previa marca como asignables. Un ciclo que recién aparece al ir
     * asignando se vuelve a validar y se omite.
     *
     * @param  array<string, mixed>  $rule
     * @return array{assigned: int, skipped: array<string, int>}
     */
    public function apply(array $rule): array
    {
        $assigned = 0;
        $skipped = [];

        foreach ($this->preview($rule) as $row) {
            if ($row['skip'] !== null || $row['manager'] === null) {
                $skipped[$row['skip'] ?? self::SKIP_NO_BOSS] = ($skipped[$row['skip'] ?? self::SKIP_NO_BOSS] ?? 0) + 1;

                continue;
            }

            $error = $this->assign($row['employee'], $row['manager']);

            if ($error === null) {
                $assigned++;
            } else {
                $skipped[$error] = ($skipped[$error] ?? 0) + 1;
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Empleados activos que tienen el cargo dado, agrupados por sucursal.
     *
     * @return Collection<int, Collection<int, Employee>>
     */
    private function holdersByBranch(int $positionId): Collection
    {
        return Employee::query()
            ->with('branch')
            ->where('status', 'active')
            ->whereHas('activeContract', fn ($contract) => $contract->where('position_id', $positionId))
            ->get()
            ->groupBy('branch_id');
    }
}
