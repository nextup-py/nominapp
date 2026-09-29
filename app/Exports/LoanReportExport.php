<?php

namespace App\Exports;

use App\Models\Loan;
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
 * Exporta préstamos con los filtros activos del reporte.
 *
 * Cada fila representa un préstamo individual.
 * Soporta selección de columnas mediante availableColumns() / defaultColumns().
 */
class LoanReportExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<string>  $columns  Claves de columnas a incluir (ver availableColumns()).
     * @param  array<string, mixed>  $filters  Filtros activos del reporte.
     */
    public function __construct(
        protected array $columns = [],
        protected array $filters = [],
    ) {
        if (empty($this->columns)) {
            $this->columns = static::defaultColumns();
        }
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
            'company_name' => 'Empresa',
            'branch_name' => 'Sucursal',
            'amount' => 'Monto',
            'installments_count' => 'Cuotas',
            'installment_amount' => 'Cuota Mensual',
            'interest_rate' => 'Tasa Interés %',
            'outstanding_balance' => 'Saldo Pendiente',
            'paid_installments_count' => 'Cuotas Pagadas',
            'pending_installments_count' => 'Cuotas Pendientes',
            'payment_method' => 'Método de pago',
            'status' => 'Estado',
            'granted_at' => 'Fecha Otorgamiento',
            'granted_by_name' => 'Aprobado Por',
        ];
    }

    /**
     * Columnas seleccionadas por defecto (todas excepto Empresa y Sucursal, que son condicionales).
     *
     * @return array<string>
     */
    public static function defaultColumns(): array
    {
        return [
            'employee_name', 'ci', 'company_name', 'branch_name',
            'amount', 'installments_count', 'installment_amount',
            'interest_rate', 'outstanding_balance',
            'paid_installments_count', 'pending_installments_count',
            'payment_method', 'status', 'granted_at', 'granted_by_name',
        ];
    }

    /**
     * Query base: préstamos con joins a employees, branches, companies y users.
     */
    public function query(): Builder
    {
        $companyId = $this->filters['company_id'] ?? null;
        $branchId = $this->filters['branch_id'] ?? null;
        $status = $this->filters['status'] ?? null;
        $employeeId = $this->filters['employee_id'] ?? null;
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;
        $paymentMethod = $this->filters['payment_method'] ?? null;

        return Loan::query()
            ->select([
                'loans.id',
                'loans.amount',
                'loans.installments_count',
                'loans.installment_amount',
                'loans.interest_rate',
                'loans.outstanding_balance',
                'loans.payment_method',
                'loans.status',
                'loans.granted_at',
                DB::raw("CONCAT(employees.first_name, ' ', employees.last_name) AS employee_name"),
                'employees.ci',
                'branches.name AS branch_name',
                'companies.name AS company_name',
                'users.name AS granted_by_name',
                DB::raw('(SELECT COUNT(*) FROM loan_installments WHERE loan_installments.loan_id = loans.id AND loan_installments.status = "paid") AS paid_installments_count'),
                DB::raw('(SELECT COUNT(*) FROM loan_installments WHERE loan_installments.loan_id = loans.id AND loan_installments.status = "pending") AS pending_installments_count'),
            ])
            ->join('employees', 'loans.employee_id', '=', 'employees.id')
            ->join('branches', 'employees.branch_id', '=', 'branches.id')
            ->join('companies', 'branches.company_id', '=', 'companies.id')
            ->leftJoin('users', 'loans.granted_by_id', '=', 'users.id')
            ->when($companyId, fn ($q) => $q->where('branches.company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($status, fn ($q) => $q->where('loans.status', $status))
            ->when($employeeId, fn ($q) => $q->where('loans.employee_id', $employeeId))
            ->when($from, fn ($q) => $q->whereDate('loans.granted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('loans.granted_at', '<=', $to))
            ->when($paymentMethod, fn ($q) => $q->where('loans.payment_method', $paymentMethod))
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('loans.granted_at');
    }

    /**
     * Encabezados filtrados según las columnas seleccionadas.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        $all = static::availableColumns();

        return array_values(array_intersect_key($all, array_flip($this->columns)));
    }

    /**
     * Mapea cada registro a una fila del Excel con solo las columnas seleccionadas.
     *
     * @param  mixed  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $grantedAt = $row->granted_at;
        $grantedAtFormatted = $grantedAt
            ? ($grantedAt instanceof Carbon ? $grantedAt->format('d/m/Y') : Carbon::parse($grantedAt)->format('d/m/Y'))
            : '';

        $all = [
            'employee_name' => $row->employee_name,
            'ci' => $row->ci,
            'company_name' => $row->company_name ?? '',
            'branch_name' => $row->branch_name ?? '',
            'amount' => (float) $row->amount,
            'installments_count' => (int) $row->installments_count,
            'installment_amount' => (float) $row->installment_amount,
            'interest_rate' => $row->interest_rate.'%',
            'outstanding_balance' => (float) $row->outstanding_balance,
            'paid_installments_count' => (int) $row->paid_installments_count,
            'pending_installments_count' => (int) $row->pending_installments_count,
            'payment_method' => Loan::getPaymentMethodLabel($row->payment_method ?? 'cash'),
            'status' => Loan::getStatusLabel($row->status),
            'granted_at' => $grantedAtFormatted,
            'granted_by_name' => $row->granted_by_name ?? '',
        ];

        return array_values(array_intersect_key($all, array_flip($this->columns)));
    }

    /**
     * Aplica estilos al encabezado de la hoja de cálculo.
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
