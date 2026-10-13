<?php

namespace App\Exports;

use App\Models\Branch;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BranchesExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  int|null  $companyId  Limita a una empresa (y oculta la columna Empresa).
     * @param  array<int, int>|null  $ids  Limita a estas sucursales (las del listado filtrado); null = todas.
     */
    public function __construct(private ?int $companyId = null, private ?array $ids = null) {}

    public function query()
    {
        return Branch::withCount('activeEmployees')
            ->with('company')
            ->when($this->companyId, fn ($q) => $q->where('company_id', $this->companyId))
            ->when($this->ids !== null, fn ($q) => $q->whereIn('branches.id', $this->ids))
            ->orderBy('name');
    }

    public function headings(): array
    {
        return $this->companyId
            ? ['Nombre', 'Ciudad', 'Dirección', 'Teléfono', 'Correo', 'Empleados Activos', 'Estado']
            : ['Empresa', 'Nombre', 'Ciudad', 'Dirección', 'Teléfono', 'Correo', 'Empleados Activos', 'Estado'];
    }

    public function map($branch): array
    {
        $row = $this->companyId ? [] : [$branch->company?->trade_name ?? $branch->company?->name ?? '—'];

        return array_merge($row, [
            $branch->name,
            $branch->city ?? '—',
            $branch->address ?? '—',
            $branch->phone ?? '—',
            $branch->email ?? '—',
            $branch->active_employees_count,
            $branch->is_active ? 'Activa' : 'Inactiva',
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
