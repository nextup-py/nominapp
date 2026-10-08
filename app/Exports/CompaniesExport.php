<?php

namespace App\Exports;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Exporta empresas a Excel con las columnas elegidas y, opcionalmente, solo un conjunto de ids (los filtrados en el listado). */
class CompaniesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<int, string>|null  $columns  Claves de {@see self::availableColumns()}; null = las predeterminadas.
     * @param  array<int, int>|null  $ids  Limita a estas empresas; null = todas.
     */
    public function __construct(
        protected ?array $columns = null,
        protected ?array $ids = null,
    ) {
        $this->columns = array_values(array_intersect(
            array_keys(static::availableColumns()),
            $columns ?? static::defaultColumns(),
        ));
    }

    /**
     * Catálogo de columnas exportables (clave => encabezado).
     *
     * @return array<string, string>
     */
    public static function availableColumns(): array
    {
        return [
            'name' => 'Razón Social',
            'trade_name' => 'Nombre Comercial',
            'ruc' => 'RUC',
            'employer_number' => 'Nro. Patronal IPS',
            'legal_type' => 'Tipo Societario',
            'legal_rep_name' => 'Representante Legal',
            'legal_rep_ci' => 'CI del Representante',
            'address' => 'Dirección',
            'city' => 'Ciudad',
            'phone' => 'Teléfono',
            'email' => 'Correo',
            'founded_at' => 'Fecha de Constitución',
            'branches' => 'Sucursales',
            'employees' => 'Empleados',
            'active_employees' => 'Empleados Activos',
            'pending_data' => 'Datos Pendientes',
            'status' => 'Estado',
            'created_at' => 'Creado',
        ];
    }

    /**
     * Columnas marcadas por defecto (las del export original).
     *
     * @return array<int, string>
     */
    public static function defaultColumns(): array
    {
        return ['name', 'trade_name', 'ruc', 'employer_number', 'legal_type', 'branches', 'employees', 'status', 'created_at'];
    }

    /** Empresas a exportar, con los conteos y datos pendientes que alimentan las columnas calculadas. */
    public function query(): Builder
    {
        return Company::query()
            ->withPendingData()
            ->withCount(['branches', 'employees', 'activeEmployees'])
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('companies.id', $this->ids))
            ->orderBy('name');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_map(fn (string $key) => static::availableColumns()[$key], $this->columns);
    }

    /**
     * @param  Company  $company
     * @return array<int, mixed>
     */
    public function map($company): array
    {
        return array_map(fn (string $key) => match ($key) {
            'legal_type' => $company->legal_type_label ?? '—',
            'founded_at' => $company->founded_at?->format('d/m/Y') ?? '—',
            'branches' => $company->branches_count,
            'employees' => $company->employees_count,
            'active_employees' => $company->active_employees_count,
            'pending_data' => implode(', ', $company->pendingDataList()) ?: 'Completa',
            'status' => $company->is_active ? 'Activa' : 'Inactiva',
            'created_at' => $company->created_at->format('d/m/Y'),
            default => $company->{$key} ?? '—',
        }, $this->columns);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
