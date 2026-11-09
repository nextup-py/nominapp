<?php

namespace App\Filament\Pages;

use App\Exports\SalaryReportExport;
use App\Filament\Resources\PayrollResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte de salarios: muestra un resumen por empleado para una planilla dada,
 * con desglose de percepciones, deducciones por tipo y neto a pagar.
 */
class SalaryReport extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return PayrollResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $title = 'Reporte de Salarios';

    protected static ?string $slug = 'reporte-salarios';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Reporte de Salarios';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.salary-report';

    protected ?string $heading = 'Reporte de Salarios';

    /**
     * Limpia los filtros dependientes en cascada cuando cambia un filtro padre.
     * Cadena: empresa → planilla, sucursal, departamento, empleado
     *         sucursal → empleado
     *         departamento → empleado
     */
    public function updated(string $name): void
    {
        if ($name === 'tableFilters.company_id.value') {
            $this->tableFilters['period_id']['value'] = '';
            $this->tableFilters['branch_id']['value'] = '';
            $this->tableFilters['department_id']['value'] = '';
            $this->tableFilters['employee_id']['value'] = '';
        }

        if ($name === 'tableFilters.branch_id.value' || $name === 'tableFilters.department_id.value') {
            $this->tableFilters['employee_id']['value'] = '';
        }
    }

    /**
     * Subheading dinámico con el nombre de la planilla activa.
     * Incluye el nombre de la empresa cuando hay más de una activa.
     */
    public function getSubheading(): ?string
    {
        $f = $this->tableFilters ?? [];
        $periodId = isset($f['period_id']['value']) && $f['period_id']['value'] !== ''
            ? (int) $f['period_id']['value']
            : null;

        $multipleCompanies = Company::active()->count() > 1;

        if (! $periodId) {
            return $multipleCompanies
                ? 'Seleccione una empresa y una planilla para ver el reporte'
                : 'Seleccione una planilla para ver el reporte';
        }

        $period = $multipleCompanies
            ? PayrollPeriod::with('company')->find($periodId)
            : PayrollPeriod::find($periodId);

        if (! $period) {
            return null;
        }

        return $multipleCompanies
            ? "Planilla: {$period->name} — {$period->company->name}"
            : "Planilla: {$period->name}";
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $columnOptions = SalaryReportExport::availableColumns();
        $columnDefaults = SalaryReportExport::defaultColumns();

        $subtableOptions = [
            'perceptions' => 'Desglose de Percepciones',
            'deductions' => 'Desglose de Deducciones',
            'payment_methods' => 'Resumen por Método de Pago',
        ];

        return [
            Action::make('export_pdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->disabled(fn () => ! ($this->tableFilters['period_id']['value'] ?? null))
                ->modalHeading('Exportar reporte en PDF')
                ->modalSubmitActionLabel('Generar PDF')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas de la tabla principal')
                        ->options($columnOptions)
                        ->default($columnDefaults)
                        ->columns(3)
                        ->required(),
                    CheckboxList::make('subtables')
                        ->label('Sub-tablas a incluir')
                        ->options($subtableOptions)
                        ->default(array_keys($subtableOptions))
                        ->columns(3),
                    Radio::make('orientation')
                        ->label('Orientación de la página')
                        ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
                        ->default('landscape')
                        ->inline()
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$periodId, $companyId, $branchId, $status, $paymentMethod, $departmentId, $employeeId] = $this->resolveActiveFilters();

                    $params = array_filter([
                        'periodId' => $periodId,
                        'companyId' => $companyId,
                        'branchId' => $branchId,
                        'departmentId' => $departmentId,
                        'employeeId' => $employeeId,
                        'status' => $status,
                        'paymentMethod' => $paymentMethod,
                        'columns' => implode(',', $data['columns']),
                        'subtables' => implode(',', $data['subtables'] ?? []),
                        'orientation' => $data['orientation'] ?? 'landscape',
                    ], fn ($v) => $v !== null);

                    $url = route('salary-report.pdf', $params);
                    $this->js("window.open('".addslashes($url)."', '_blank')");
                }),

            Action::make('export_excel')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->disabled(fn () => ! ($this->tableFilters['period_id']['value'] ?? null))
                ->modalHeading('Exportar reporte de salarios')
                ->modalDescription('Seleccione las columnas a incluir en el archivo Excel.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options($columnOptions)
                        ->default($columnDefaults)
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$periodId, $companyId, $branchId, $status, $paymentMethod, $departmentId, $employeeId] = $this->resolveActiveFilters();

                    Notification::make()
                        ->success()
                        ->title('Exportación iniciada')
                        ->body('El archivo se descargará en breve.')
                        ->send();

                    return Excel::download(
                        new SalaryReportExport($periodId, $companyId, $branchId, $status, $paymentMethod, $data['columns'], $departmentId, $employeeId),
                        'salarios_'.now()->format('Y_m_d_H_i').'.xlsx'
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->filters($this->buildFilters(), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->persistFiltersInSession()
            ->defaultSort('employees.last_name', 'asc')
            ->paginationPageOptions([25, 50, 100])
            ->striped()
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->getStateUsing(fn ($record) => $record->last_name.', '.$record->first_name)
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('employees.last_name', $direction)
                        ->orderBy('employees.first_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn ($q) => $q->where('employees.first_name', 'like', "%{$search}%")
                            ->orWhere('employees.last_name', 'like', "%{$search}%")
                    ))
                    ->weight('medium'),

                TextColumn::make('ci')
                    ->label('CI')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('position_name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('base_salary')
                    ->label('Salario Base')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('total_perceptions')
                    ->label('+Percepciones')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->alignRight()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ips_amount')
                    ->label('IPS')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Gs. '.number_format((float) $state, 0, ',', '.') : '—')
                    ->alignRight()
                    ->sortable()
                    ->color('warning'),

                TextColumn::make('loan_amount')
                    ->label('Descuentos por Deuda')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Gs. '.number_format((float) $state, 0, ',', '.') : '—')
                    ->alignRight()
                    ->sortable()
                    ->color('warning'),

                TextColumn::make('judicial_amount')
                    ->label('Judiciales')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Gs. '.number_format((float) $state, 0, ',', '.') : '—')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('voluntary_amount')
                    ->label('Voluntarias')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Gs. '.number_format((float) $state, 0, ',', '.') : '—')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_deductions')
                    ->label('-Deducciones')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->alignRight()
                    ->sortable()
                    ->color('danger'),

                TextColumn::make('net_salary')
                    ->label('Neto a Pagar')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->alignRight()
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('payment_method')
                    ->label('Método')
                    ->formatStateUsing(fn (?string $state) => $state ? Payroll::getPaymentMethodLabels()[$state] ?? $state : '—')
                    ->badge()
                    ->color(fn (?string $state) => $state ? (Payroll::getPaymentMethodColors()[$state] ?? 'gray') : 'gray')
                    ->icon(fn (?string $state) => $state ? (Payroll::getPaymentMethodIcons()[$state] ?? null) : null),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => Payroll::getStatusLabels()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => Payroll::getStatusColors()[$state] ?? 'gray')
                    ->icon(fn (string $state) => Payroll::getStatusIcons()[$state] ?? null),
            ])
            ->emptyStateHeading('Sin recibos para la planilla seleccionada')
            ->emptyStateDescription('Seleccione una planilla para ver el reporte de salarios.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * Query base con subqueries para montos de deducciones por tipo.
     * Retorna 0 registros si no hay planilla seleccionada — la guardia aquí es
     * necesaria porque SelectFilter puede omitir su callback cuando está inactivo.
     */
    private function buildQuery(): Builder
    {
        $periodId = isset($this->tableFilters['period_id']['value']) && $this->tableFilters['period_id']['value'] !== ''
            ? (int) $this->tableFilters['period_id']['value']
            : null;

        $query = Payroll::query()
            ->select([
                'payrolls.id',
                'payrolls.employee_id',
                'payrolls.base_salary',
                'payrolls.total_perceptions',
                'payrolls.total_deductions',
                'payrolls.net_salary',
                'payrolls.status',
                'payrolls.payment_method',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name as branch_name',
                DB::raw('(SELECT positions.name FROM contracts INNER JOIN positions ON positions.id = contracts.position_id WHERE contracts.employee_id = employees.id AND contracts.status = \'active\' LIMIT 1) as position_name'),
                DB::raw('(SELECT COALESCE(SUM(pi.amount),0) FROM payroll_items pi WHERE pi.payroll_id = payrolls.id AND pi.type = \'deduction\' AND pi.deduction_type = \'legal\') as ips_amount'),
                DB::raw('(SELECT COALESCE(SUM(pi.amount),0) FROM payroll_items pi WHERE pi.payroll_id = payrolls.id AND pi.type = \'deduction\' AND pi.deduction_type = \'loan\') as loan_amount'),
                DB::raw('(SELECT COALESCE(SUM(pi.amount),0) FROM payroll_items pi WHERE pi.payroll_id = payrolls.id AND pi.type = \'deduction\' AND pi.deduction_type = \'judicial\') as judicial_amount'),
                DB::raw('(SELECT COALESCE(SUM(pi.amount),0) FROM payroll_items pi WHERE pi.payroll_id = payrolls.id AND pi.type = \'deduction\' AND pi.deduction_type = \'voluntary\') as voluntary_amount'),
            ])
            ->join('employees', 'employees.id', '=', 'payrolls.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id');

        if (! $periodId) {
            $query->whereRaw('1=0');
        }

        return $query;
    }

    /**
     * Construye los filtros con cascada dinámica:
     * Empresa → Planilla, Sucursal, Departamento, Empleado
     * Sucursal → Empleado
     * Departamento → Empleado
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $filters = [];

        if (Company::active()->count() > 1) {
            $filters[] = Filter::make('company_id')
                ->form([
                    FormSelect::make('value')
                        ->label('Empresa')
                        ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Todas')
                        ->live(),
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('branches.company_id', $data['value'])
                    : $query
                )
                ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                    ? 'Empresa: '.Company::find($data['value'])?->name
                    : null
                );
        }

        $filters[] = Filter::make('period_id')
            ->form([
                FormSelect::make('value')
                    ->label('Planilla')
                    ->options(function () {
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;
                        $showCompanyInLabel = Company::active()->count() > 1 && ! $companyId;

                        return PayrollPeriod::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                            ->when($showCompanyInLabel, fn ($q) => $q->with('company'))
                            ->orderByDesc('start_date')
                            ->get()
                            ->mapWithKeys(fn ($p) => [
                                $p->id => $showCompanyInLabel
                                    ? "{$p->name} — {$p->company->name}"
                                    : $p->name,
                            ])
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Seleccione una planilla')
                    ->live(),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('payrolls.payroll_period_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                ? 'Planilla: '.PayrollPeriod::find($data['value'])?->name
                : null
            );

        $filters[] = Filter::make('branch_id')
            ->form([
                FormSelect::make('value')
                    ->label('Sucursal')
                    ->options(function () {
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;

                        return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Todas')
                    ->live(),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('employees.branch_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                ? 'Sucursal: '.Branch::find($data['value'])?->name
                : null
            );

        $filters[] = Filter::make('department_id')
            ->form([
                FormSelect::make('value')
                    ->label('Departamento')
                    ->options(function () {
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;

                        return Department::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Todos')
                    ->live(),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->whereExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('contracts')
                    ->join('positions', 'positions.id', '=', 'contracts.position_id')
                    ->whereColumn('contracts.employee_id', 'employees.id')
                    ->where('contracts.status', 'active')
                    ->where('positions.department_id', (int) $data['value'])
                )
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                ? 'Departamento: '.Department::find($data['value'])?->name
                : null
            );

        $filters[] = Filter::make('employee_id')
            ->form([
                FormSelect::make('value')
                    ->label('Empleado')
                    ->options(function () {
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;
                        $branchId = $this->tableFilters['branch_id']['value'] ?? null;
                        $departmentId = $this->tableFilters['department_id']['value'] ?? null;

                        return Employee::query()
                            ->where('status', 'active')
                            ->when($companyId, fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)))
                            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                            ->when($departmentId, fn ($q) => $q->whereHas('contracts', fn ($c) => $c
                                ->where('status', 'active')
                                ->whereHas('position', fn ($p) => $p->where('department_id', $departmentId))
                            ))
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn ($e) => [$e->id => "{$e->first_name} {$e->last_name} (CI: {$e->ci})"])
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Todos'),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('payrolls.employee_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(function (array $data): ?string {
                if (! filled($data['value'])) {
                    return null;
                }
                $employee = Employee::find($data['value']);

                return $employee ? "Empleado: {$employee->first_name} {$employee->last_name}" : null;
            });

        return array_merge($filters, [
            SelectFilter::make('status')
                ->label('Estado')
                ->options(Payroll::getStatusLabels())
                ->query(
                    fn (Builder $query, array $data) => $data['value']
                        ? $query->where('payrolls.status', $data['value'])
                        : $query
                )
                ->native(false),

            SelectFilter::make('payment_method')
                ->label('Método de pago')
                ->options(Payroll::getPaymentMethodOptions())
                ->query(
                    fn (Builder $query, array $data) => $data['value']
                        ? $query->where('payrolls.payment_method', $data['value'])
                        : $query
                )
                ->native(false),
        ]);
    }

    /**
     * Extrae los valores activos de los filtros para export/PDF.
     *
     * @return array{int|null, int|null, int|null, string|null, string|null, int|null, int|null}
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            isset($f['period_id']['value']) && $f['period_id']['value'] !== '' ? (int) $f['period_id']['value'] : null,
            isset($f['company_id']['value']) && $f['company_id']['value'] !== '' ? (int) $f['company_id']['value'] : null,
            isset($f['branch_id']['value']) && $f['branch_id']['value'] !== '' ? (int) $f['branch_id']['value'] : null,
            isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null,
            isset($f['payment_method']['value']) && $f['payment_method']['value'] !== '' ? $f['payment_method']['value'] : null,
            isset($f['department_id']['value']) && $f['department_id']['value'] !== '' ? (int) $f['department_id']['value'] : null,
            isset($f['employee_id']['value']) && $f['employee_id']['value'] !== '' ? (int) $f['employee_id']['value'] : null,
        ];
    }
}
