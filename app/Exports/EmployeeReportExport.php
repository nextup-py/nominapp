<?php

namespace App\Exports;

use App\Models\Contract;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta el reporte de empleados con columnas seleccionables por el usuario.
 *
 * Los datos de contrato provienen del contrato activo del empleado.
 */
class EmployeeReportExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<string>  $columns  Claves de columnas a incluir (ver availableColumns())
     */
    public function __construct(
        protected ?int $companyId = null,
        protected ?int $branchId = null,
        protected ?string $gender = null,
        protected ?int $birthMonth = null,
        protected ?string $status = null,
        protected ?int $departmentId = null,
        protected ?string $contractType = null,
        protected ?string $paymentMethod = null,
        protected array $columns = [],
        protected ?string $registeredFrom = null,
        protected ?string $registeredUntil = null,
        protected ?string $endDateFrom = null,
        protected ?string $endDateUntil = null,
    ) {
        if (empty($this->columns)) {
            $this->columns = array_keys(static::availableColumns());
        }
    }

    /** Si el usuario actual puede ver salarios (permiso de contratos). */
    public static function canSeeSalary(): bool
    {
        return auth()->user()?->can('view_any_contract') ?? false;
    }

    /**
     * Quita de las columnas pedidas las que el usuario actual no puede ver (hoy, el salario sin permiso de contratos).
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    public static function visibleColumns(array $columns): array
    {
        return static::canSeeSalary() ? $columns : array_values(array_diff($columns, ['salary']));
    }

    /**
     * Todas las columnas disponibles para el export: clave => label.
     *
     * @return array<string, string>
     */
    public static function availableColumns(): array
    {
        return [
            'employee_name' => 'Empleado',
            'ci' => 'CI',
            'gender' => 'Género',
            'age' => 'Edad',
            'birthday' => 'Cumpleaños',
            'hire_date' => 'Fecha ingreso',
            'years_of_service' => 'Antigüedad (años)',
            'registered_at' => 'Fecha de registro',
            'end_date' => 'Fecha de baja',
            'salary' => 'Salario (Gs.)',
            'contract_type' => 'Tipo de contrato',
            'payment_method' => 'Método de pago',
            'position_name' => 'Cargo',
            'department_name' => 'Departamento',
            'branch_name' => 'Sucursal',
            'company_name' => 'Empresa',
            'status' => 'Estado',
            'phone' => 'Teléfono',
        ];
    }

    /**
     * Columnas seleccionadas por defecto (todas).
     *
     * @return array<string>
     */
    public static function defaultColumns(): array
    {
        return array_keys(static::availableColumns());
    }

    /**
     * Query base con filtros aplicados.
     */
    public function query(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'employees.gender',
                'employees.birth_date',
                'employees.status',
                'employees.phone',
                'employees.created_at',
                'branches.name as branch_name',
                'companies.name as company_name',
                'contracts.start_date as hire_date',
                'contracts.salary',
                'contracts.salary_type',
                'contracts.type as contract_type',
                'contracts.payment_method',
                'positions.name as position_name',
                'departments.name as department_name',
                DB::raw('TIMESTAMPDIFF(YEAR, contracts.start_date, CURDATE()) AS years_of_service'),
                DB::raw('(SELECT MAX(c2.end_date) FROM contracts c2 WHERE c2.employee_id = employees.id) AS last_end_date'),
            ])
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->join('companies', 'companies.id', '=', 'branches.company_id')
            ->leftJoin('contracts', function ($join) {
                $join->on('contracts.employee_id', '=', 'employees.id')
                    ->where('contracts.status', '=', 'active');
            })
            ->leftJoin('positions', 'positions.id', '=', 'contracts.position_id')
            ->leftJoin('departments', 'departments.id', '=', 'contracts.department_id')
            ->when($this->companyId, fn ($q) => $q->where('branches.company_id', $this->companyId))
            ->when($this->branchId, fn ($q) => $q->where('employees.branch_id', $this->branchId))
            ->when($this->gender, fn ($q) => $q->where('employees.gender', $this->gender))
            ->when($this->birthMonth, fn ($q) => $q->whereRaw('MONTH(employees.birth_date) = ?', [$this->birthMonth]))
            ->when($this->status === 'sin_contrato', fn ($q) => $q->whereDoesntHave('contracts'))
            ->when($this->status && $this->status !== 'sin_contrato', fn ($q) => $q->where('employees.status', $this->status))
            ->when($this->departmentId, fn ($q) => $q->where('contracts.department_id', $this->departmentId))
            ->when($this->contractType, fn ($q) => $q->where('contracts.type', $this->contractType))
            ->when($this->paymentMethod, fn ($q) => $q->where('contracts.payment_method', $this->paymentMethod))
            ->when($this->registeredFrom, fn ($q) => $q->where('employees.created_at', '>=', Carbon::parse($this->registeredFrom)->startOfDay()))
            ->when($this->registeredUntil, fn ($q) => $q->where('employees.created_at', '<=', Carbon::parse($this->registeredUntil)->endOfDay()))
            ->when($this->endDateFrom || $this->endDateUntil, fn ($q) => $q->whereHas('contracts', function ($q2) {
                if ($this->endDateFrom) {
                    $q2->where('end_date', '>=', $this->endDateFrom);
                }
                if ($this->endDateUntil) {
                    $q2->where('end_date', '<=', $this->endDateUntil);
                }
            }))
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name');
    }

    /**
     * Encabezados de columna según la selección del usuario.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        $all = static::availableColumns();

        return array_values(array_intersect_key($all, array_flip($this->columns)));
    }

    /**
     * Mapea cada empleado a una fila del Excel con solo las columnas seleccionadas.
     *
     * @param  mixed  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $genderOptions = Employee::getGenderOptions();
        $statusOptions = Employee::getStatusOptions();
        $contractTypes = Contract::getTypeOptions();
        $paymentMethods = Employee::getPaymentMethodOptions();

        $all = [
            'employee_name' => $row->last_name.', '.$row->first_name,
            'ci' => $row->ci,
            'gender' => $genderOptions[$row->gender] ?? '',
            'age' => $row->birth_date ? $row->birth_date->age : '',
            'birthday' => $row->birth_date ? $row->birth_date->day.' de '.$row->birth_date->locale('es')->isoFormat('MMMM') : '',
            'hire_date' => $row->hire_date ? Carbon::parse($row->hire_date)->format('d/m/Y') : '',
            'years_of_service' => (function () use ($row): string {
                if (! $row->hire_date || $row->status !== 'active') {
                    return '';
                }
                $hire = Carbon::parse($row->hire_date);
                $years = (int) $hire->diffInYears(now());
                $totalMonths = (int) $hire->diffInMonths(now());
                if ($years >= 1) {
                    $remainderMonths = $totalMonths - ($years * 12);

                    return $years.' año'.($years !== 1 ? 's' : '').($remainderMonths > 0 ? ' '.$remainderMonths.' mes'.($remainderMonths !== 1 ? 'es' : '') : '');
                }
                if ($totalMonths >= 1) {
                    return $totalMonths.' mes'.($totalMonths !== 1 ? 'es' : '');
                }
                $days = (int) $hire->diffInDays(now());

                return $days.' día'.($days !== 1 ? 's' : '');
            })(),
            'registered_at' => $row->created_at ? Carbon::parse($row->created_at)->format('d/m/Y') : '',
            'end_date' => $row->last_end_date ? Carbon::parse($row->last_end_date)->format('d/m/Y') : '',
            'salary' => $row->salary ? (float) $row->salary : '',
            'contract_type' => $contractTypes[$row->contract_type] ?? '',
            'payment_method' => $paymentMethods[$row->payment_method] ?? '',
            'position_name' => $row->position_name ?? '',
            'department_name' => $row->department_name ?? '',
            'branch_name' => $row->branch_name,
            'company_name' => $row->company_name,
            'status' => $statusOptions[$row->status] ?? $row->status,
            'phone' => $row->phone ?? '',
        ];

        return array_values(array_intersect_key($all, array_flip($this->columns)));
    }

    /**
     * Aplica estilos al encabezado de la hoja.
     *
     * @return array<int|string, mixed>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
