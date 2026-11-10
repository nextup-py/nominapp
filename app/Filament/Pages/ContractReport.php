<?php

namespace App\Filament\Pages;

use App\Exports\ContractReportExport;
use App\Filament\Resources\ContractResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
 * Reporte de contratos: vencimientos, períodos de prueba, sin contrato activo,
 * antigüedad, suspendidos, todos activos y rescindidos recientes.
 * Solo incluye empleados con status = active (excepto tabs de suspendidos y rescindidos).
 */
class ContractReport extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return ContractResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $slug = 'reporte-contratos';

    protected static ?string $title = 'Reporte de Contratos';

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Reporte de Contratos';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.contract-report';

    protected ?string $heading = 'Reporte de Contratos';

    /**
     * Tab activo.
     *
     * @var string 'vencer'|'prueba'|'sin_contrato'|'antiguedad'|'suspendidos'|'activos'|'rescindidos'
     */
    public string $activeTab = 'vencer';

    /** Reconstruye la tabla al cambiar de tab. */
    public function updatedActiveTab(): void
    {
        $this->resetTable();
    }

    /**
     * Limpia filtros hijos al cambiar el filtro padre.
     * Empresa → limpia Sucursal, Departamento y Cargo.
     * Departamento → limpia Cargo.
     */
    public function updated(string $name): void
    {
        if ($name === 'tableFilters.company_id.value') {
            $this->tableFilters['branch_id']['value'] = '';
            $this->tableFilters['department_id']['value'] = '';
            $this->tableFilters['position_id']['value'] = '';
        }

        if ($name === 'tableFilters.department_id.value') {
            $this->tableFilters['position_id']['value'] = '';
        }
    }

    /** Subheading dinámico según el tab activo. */
    public function getSubheading(): ?string
    {
        return match ($this->activeTab) {
            'vencer' => 'Contratos activos con fecha de vencimiento definida',
            'prueba' => 'Empleados actualmente en período de prueba',
            'sin_contrato' => 'Empleados sin ningún contrato activo',
            'antiguedad' => 'Contratos activos ordenados por fecha de inicio',
            'suspendidos' => 'Contratos con estado suspendido',
            'activos' => 'Todos los contratos activos',
            'rescindidos' => 'Contratos rescindidos o terminados',
            default => null,
        };
    }

    /**
     * Acciones del encabezado: exportar PDF y Excel con selector de columnas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $columnOptions = ContractReportExport::availableColumns($this->activeTab);
        $columnDefaults = ContractReportExport::defaultColumns($this->activeTab);
        $orientationThreshold = 6;

        if (Company::active()->count() <= 1) {
            unset($columnOptions['company_name']);
            $columnDefaults = array_values(array_diff($columnDefaults, ['company_name']));
        }

        return [
            Action::make('export_pdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Exportar reporte en PDF')
                ->modalSubmitActionLabel('Generar PDF')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options($columnOptions)
                        ->default($columnDefaults)
                        ->columns(3)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set) use ($orientationThreshold) {
                            $count = count($get('columns') ?? []);
                            $set('orientation', $count <= $orientationThreshold ? 'portrait' : 'landscape');
                        }),
                    Radio::make('orientation')
                        ->label('Orientación de la página')
                        ->helperText('Se ajusta automáticamente: Vertical para ≤ '.$orientationThreshold.' columnas, Horizontal para más.')
                        ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
                        ->default(count($columnDefaults) > $orientationThreshold ? 'landscape' : 'portrait')
                        ->inline()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $filters = $this->resolveActiveFilters();

                    $params = array_filter(array_merge([
                        'tab' => $this->activeTab,
                        'columns' => implode(',', $data['columns']),
                        'orientation' => $data['orientation'] ?? 'landscape',
                    ], $filters), fn ($v) => $v !== null && $v !== '');

                    $url = route('contracts.report.pdf', $params);
                    $this->js("window.open('".addslashes($url)."', '_blank')");
                }),

            Action::make('export_excel')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->modalHeading('Exportar reporte')
                ->modalDescription('Seleccione las columnas a incluir.')
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
                    $filters = $this->resolveActiveFilters();

                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new ContractReportExport($this->activeTab, $data['columns'], $filters),
                        'contratos_'.$this->activeTab.'_'.now()->format('Y_m_d_H_i').'.xlsx'
                    );
                }),
        ];
    }

    /**
     * Define la tabla con columnas y filtros dinámicos según el tab activo.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->filters($this->buildFilters(), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->persistFiltersInSession()
            ->paginationPageOptions([25, 50, 100])
            ->striped()
            ->columns($this->buildColumns())
            ->emptyStateHeading('Sin registros para los filtros seleccionados')
            ->emptyStateIcon('heroicon-o-document-check');
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    /**
     * Retorna la query correspondiente al tab activo.
     */
    private function buildQuery(): Builder
    {
        return match ($this->activeTab) {
            'vencer' => $this->queryVencer(),
            'prueba' => $this->queryPrueba(),
            'sin_contrato' => $this->querySinContrato(),
            'antiguedad' => $this->queryAntiguedad(),
            'suspendidos' => $this->querySuspendidos(),
            'activos' => $this->queryActivos(),
            'rescindidos' => $this->queryRescindidos(),
            default => $this->queryVencer(),
        };
    }

    /**
     * Base de contratos con joins comunes para empleado, sucursal, empresa y cargo.
     * Cuando el estado es 'active', restringe a empleados activos.
     *
     * @param  string  $status  Estado del contrato a filtrar.
     */
    private function baseContractQuery(string $status = 'active'): Builder
    {
        $query = Contract::query()
            ->select([
                'contracts.id',
                'contracts.start_date',
                'contracts.end_date',
                'contracts.trial_days',
                'contracts.status',
                'contracts.type',
                'contracts.salary_type',
                'contracts.salary',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name as branch_name',
                'companies.id as company_id',
                'companies.name as company_name',
                'positions.name as position_name',
            ])
            ->join('employees', 'employees.id', '=', 'contracts.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('companies', 'companies.id', '=', 'branches.company_id')
            ->leftJoin('positions', 'positions.id', '=', 'contracts.position_id')
            ->where('contracts.status', $status);

        // Solo empleados activos para contratos vigentes
        if ($status === 'active') {
            $query->where('employees.status', 'active');
        }

        return $query;
    }

    /**
     * Tab "Por Vencer": contratos activos (empleados activos) con end_date, ordenados por días restantes ASC.
     */
    private function queryVencer(): Builder
    {
        return $this->baseContractQuery()
            ->addSelect([
                DB::raw('DATEDIFF(contracts.end_date, CURDATE()) as days_until_expiry'),
            ])
            ->whereNotNull('contracts.end_date')
            ->whereRaw('DATEDIFF(contracts.end_date, CURDATE()) >= 0')
            ->orderByRaw('DATEDIFF(contracts.end_date, CURDATE()) ASC');
    }

    /**
     * Tab "Período de Prueba": contratos activos (empleados activos) en período de prueba.
     */
    private function queryPrueba(): Builder
    {
        return $this->baseContractQuery()
            ->addSelect([
                DB::raw('DATEDIFF(DATE_ADD(contracts.start_date, INTERVAL contracts.trial_days DAY), CURDATE()) as days_until_trial_end'),
                DB::raw('DATE_ADD(contracts.start_date, INTERVAL contracts.trial_days DAY) as trial_end_date'),
            ])
            ->where('contracts.trial_days', '>', 0)
            ->whereRaw('DATE_ADD(contracts.start_date, INTERVAL contracts.trial_days DAY) >= CURDATE()')
            ->orderByRaw('DATEDIFF(DATE_ADD(contracts.start_date, INTERVAL contracts.trial_days DAY), CURDATE()) ASC');
    }

    /**
     * Tab "Sin Contrato": empleados activos sin ningún contrato activo.
     */
    private function querySinContrato(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'employees.status as employee_status',
                'employees.created_at',
                'branches.name as branch_name',
                'companies.id as company_id',
                'companies.name as company_name',
            ])
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('companies', 'companies.id', '=', 'branches.company_id')
            ->where('employees.status', 'active')
            ->whereDoesntHave('contracts', fn ($q) => $q->where('status', 'active'))
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name');
    }

    /**
     * Tab "Por Antigüedad": contratos activos (empleados activos), ordenados por start_date ASC.
     */
    private function queryAntiguedad(): Builder
    {
        return $this->baseContractQuery()
            ->addSelect([
                DB::raw('TIMESTAMPDIFF(YEAR, contracts.start_date, CURDATE()) as years_of_service'),
                DB::raw('TIMESTAMPDIFF(MONTH, contracts.start_date, CURDATE()) as months_of_service'),
            ])
            ->orderBy('contracts.start_date', 'asc');
    }

    /**
     * Tab "Suspendidos": contratos suspendidos (sin restricción de status de empleado).
     */
    private function querySuspendidos(): Builder
    {
        return $this->baseContractQuery('suspended')
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name');
    }

    /**
     * Tab "Todos Activos": todos los contratos activos (empleados activos), ordenados por apellido.
     */
    private function queryActivos(): Builder
    {
        return $this->baseContractQuery()
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name');
    }

    /**
     * Tab "Rescindidos": contratos terminados (sin restricción de status de empleado).
     */
    private function queryRescindidos(): Builder
    {
        return Contract::query()
            ->select([
                'contracts.id',
                'contracts.start_date',
                'contracts.end_date',
                'contracts.terminated_at',
                'contracts.type',
                'contracts.salary_type',
                'contracts.salary',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name as branch_name',
                'companies.id as company_id',
                'companies.name as company_name',
                'positions.name as position_name',
            ])
            ->join('employees', 'employees.id', '=', 'contracts.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('companies', 'companies.id', '=', 'branches.company_id')
            ->leftJoin('positions', 'positions.id', '=', 'contracts.position_id')
            ->where('contracts.status', 'terminated')
            ->orderByRaw('contracts.terminated_at DESC, contracts.updated_at DESC');
    }

    // =========================================================================
    // COLUMNS
    // =========================================================================

    /**
     * Construye el array de columnas de la tabla según el tab activo.
     *
     * @return array<int, TextColumn>
     */
    private function buildColumns(): array
    {
        return [
            // ── Siempre visibles ─────────────────────────────────────────────
            TextColumn::make('employee_name')
                ->label('Empleado')
                ->getStateUsing(fn ($record) => strtoupper($record->last_name).', '.$record->first_name)
                ->sortable(query: fn (Builder $query, string $direction) => $query
                    ->orderBy('employees.last_name', $direction)
                    ->orderBy('employees.first_name', $direction)
                )
                ->searchable(query: fn (Builder $query, string $search) => $query->where(
                    fn ($q) => $q
                        ->where('employees.first_name', 'like', "%{$search}%")
                        ->orWhere('employees.last_name', 'like', "%{$search}%")
                ))
                ->weight('medium'),

            TextColumn::make('ci')
                ->label('CI')
                ->badge()
                ->color('gray')
                ->searchable(query: fn (Builder $query, string $search) => $query->where('employees.ci', 'like', "%{$search}%"))
                ->copyable(),

            TextColumn::make('branch_name')
                ->label('Sucursal')
                ->badge()
                ->color('info')
                ->icon('heroicon-o-building-storefront'),

            // ── Solo tabs con contrato ────────────────────────────────────────
            TextColumn::make('position_name')
                ->label('Cargo')
                ->icon('heroicon-o-briefcase')
                ->placeholder('—')
                ->hidden(fn () => $this->activeTab === 'sin_contrato'),

            TextColumn::make('type')
                ->label('Tipo')
                ->formatStateUsing(fn ($state) => Contract::getTypeLabel($state))
                ->badge()
                ->color(fn ($state) => Contract::getTypeColor($state))
                ->hidden(fn () => $this->activeTab === 'sin_contrato'),

            // ── Salario (tabs donde es relevante) ────────────────────────────
            TextColumn::make('salary_type')
                ->label('Tipo Sal.')
                ->formatStateUsing(fn ($state) => match ($state) {
                    'mensual' => 'Mensual',
                    'jornal' => 'Jornal',
                    default => $state ?? '—',
                })
                ->badge()
                ->color(fn ($state) => $state === 'mensual' ? 'success' : 'warning')
                ->hidden(fn () => ! in_array($this->activeTab, ['antiguedad', 'suspendidos', 'activos', 'rescindidos'])),

            TextColumn::make('salary')
                ->label('Salario')
                ->getStateUsing(fn ($record) => $record->salary !== null
                    ? 'Gs. '.number_format((float) $record->salary, 0, ',', '.')
                    : '—'
                )
                ->hidden(fn () => ! in_array($this->activeTab, ['antiguedad', 'suspendidos', 'activos', 'rescindidos'])),

            // ── Fechas de contrato ────────────────────────────────────────────
            TextColumn::make('start_date')
                ->label('Inicio')
                ->date('d/m/Y')
                ->sortable()
                ->hidden(fn () => $this->activeTab === 'sin_contrato'),

            TextColumn::make('end_date')
                ->label('Vencimiento')
                ->date('d/m/Y')
                ->sortable()
                ->placeholder('Indefinido')
                ->hidden(fn () => in_array($this->activeTab, ['prueba', 'sin_contrato', 'antiguedad', 'suspendidos'])),

            TextColumn::make('terminated_at')
                ->label('Rescindido el')
                ->date('d/m/Y')
                ->sortable()
                ->hidden(fn () => $this->activeTab !== 'rescindidos'),

            // ── Prueba ────────────────────────────────────────────────────────
            TextColumn::make('trial_end_formatted')
                ->label('Fin de Prueba')
                ->getStateUsing(fn ($record) => $record->trial_end_date
                    ? Carbon::parse($record->trial_end_date)->format('d/m/Y')
                    : '—'
                )
                ->hidden(fn () => $this->activeTab !== 'prueba'),

            TextColumn::make('trial_days')
                ->label('Días de Prueba')
                ->suffix(' días')
                ->badge()
                ->color('gray')
                ->hidden(fn () => $this->activeTab !== 'prueba'),

            // ── Días restantes (vencer / prueba) ─────────────────────────────
            TextColumn::make('dias_restantes')
                ->label('Días Restantes')
                ->getStateUsing(fn ($record) => $this->activeTab === 'prueba'
                    ? $record->days_until_trial_end
                    : $record->days_until_expiry
                )
                ->badge()
                ->color(function ($record) {
                    $days = $this->activeTab === 'prueba'
                        ? $record->days_until_trial_end
                        : $record->days_until_expiry;
                    if ($days === null) {
                        return 'gray';
                    }
                    if ($days <= 15) {
                        return 'danger';
                    }
                    if ($days <= 30) {
                        return 'warning';
                    }

                    return 'success';
                })
                ->suffix(' días')
                ->hidden(fn () => ! in_array($this->activeTab, ['vencer', 'prueba'])),

            // ── Antigüedad ────────────────────────────────────────────────────
            TextColumn::make('years_of_service')
                ->label('Antigüedad')
                ->getStateUsing(function ($record) {
                    $years = (int) ($record->years_of_service ?? 0);
                    $months = (int) ($record->months_of_service ?? 0);
                    if ($years >= 1) {
                        $rem = $months - ($years * 12);

                        return $years.' año'.($years !== 1 ? 's' : '').($rem > 0 ? ', '.$rem.' mes'.($rem !== 1 ? 'es' : '') : '');
                    }

                    return $months.' mes'.($months !== 1 ? 'es' : '');
                })
                ->badge()
                ->color(function ($record) {
                    $years = (int) ($record->years_of_service ?? 0);
                    if ($years >= 10) {
                        return 'success';
                    }
                    if ($years >= 5) {
                        return 'info';
                    }
                    if ($years >= 1) {
                        return 'warning';
                    }

                    return 'gray';
                })
                ->hidden(fn () => $this->activeTab !== 'antiguedad'),

            // ── Fecha de registro (sin contrato) ─────────────────────────────
            TextColumn::make('created_at')
                ->label('Registrado el')
                ->date('d/m/Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->hidden(fn () => $this->activeTab !== 'sin_contrato'),

            // ── Estado empleado (sin contrato) ────────────────────────────────
            TextColumn::make('employee_status')
                ->label('Estado')
                ->formatStateUsing(fn ($state) => match ($state) {
                    'active' => 'Activo',
                    'inactive' => 'Inactivo',
                    'draft' => 'Borrador',
                    'suspended' => 'Suspendido',
                    default => $state ?? '—',
                })
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'active' => 'success',
                    'inactive' => 'gray',
                    'suspended' => 'warning',
                    default => 'gray',
                })
                ->hidden(fn () => $this->activeTab !== 'sin_contrato'),
        ];
    }

    // =========================================================================
    // FILTERS
    // =========================================================================

    /**
     * Construye los filtros dinámicos según el tab activo.
     * Los filtros Empresa → Sucursal y Departamento → Cargo son en cascada.
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $filters = [];

        // ── Empresa (cascada padre) ──────────────────────────────────────────
        if (Company::active()->count() > 1) {
            $filters[] = Filter::make('company_id')
                ->label('Empresa')
                ->form([
                    FormSelect::make('value')
                        ->label('Empresa')
                        ->options(fn () => Company::active()->orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('Todas')
                        ->live(),
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->where('branches.company_id', (int) $data['value'])
                    : $query
                )
                ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                    ? 'Empresa: '.Company::find($data['value'])?->name
                    : null
                );
        }

        // ── Sucursal (filtrada por empresa seleccionada) ─────────────────────
        $filters[] = Filter::make('branch_id')
            ->label('Sucursal')
            ->form([
                FormSelect::make('value')
                    ->label('Sucursal')
                    ->options(function () {
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;

                        return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                            ->orderBy('name')->pluck('name', 'id')->toArray();
                    })
                    ->searchable()
                    ->placeholder('Todas')
                    ->live(),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                ? $query->where('employees.branch_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                ? 'Sucursal: '.Branch::find($data['value'])?->name
                : null
            );

        // ── Filtros de contrato (todos los tabs salvo sin_contrato) ──────────
        if ($this->activeTab !== 'sin_contrato') {
            $filters[] = SelectFilter::make('type')
                ->label('Tipo de contrato')
                ->options(Contract::getTypeOptions())
                ->query(fn (Builder $query, array $data) => $data['value']
                    ? $query->where('contracts.type', $data['value'])
                    : $query
                );

            $filters[] = SelectFilter::make('salary_type')
                ->label('Tipo de salario')
                ->options(['mensual' => 'Mensual', 'jornal' => 'Jornal'])
                ->query(fn (Builder $query, array $data) => $data['value']
                    ? $query->where('contracts.salary_type', $data['value'])
                    : $query
                );

            // ── Departamento (filtrado por empresa seleccionada) ─────────────
            $filters[] = Filter::make('department_id')
                ->label('Departamento')
                ->form([
                    FormSelect::make('value')
                        ->label('Departamento')
                        ->options(function () {
                            $companyId = $this->tableFilters['company_id']['value'] ?? null;

                            return Department::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                ->orderBy('name')->pluck('name', 'id')->toArray();
                        })
                        ->searchable()
                        ->placeholder('Todos')
                        ->live(),
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->where('contracts.department_id', (int) $data['value'])
                    : $query
                )
                ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                    ? 'Dpto: '.Department::find($data['value'])?->name
                    : null
                );

            // ── Cargo (filtrado por departamento seleccionado) ───────────────
            $filters[] = Filter::make('position_id')
                ->label('Cargo')
                ->form([
                    FormSelect::make('value')
                        ->label('Cargo')
                        ->options(function () {
                            $deptId = $this->tableFilters['department_id']['value'] ?? null;

                            return Position::when($deptId, fn ($q) => $q->where('department_id', $deptId))
                                ->orderBy('name')->pluck('name', 'id')->toArray();
                        })
                        ->searchable()
                        ->placeholder('Todos')
                        ->live(),
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->where('contracts.position_id', (int) $data['value'])
                    : $query
                )
                ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                    ? 'Cargo: '.Position::find($data['value'])?->name
                    : null
                );
        }

        // ── "Vencer en X días" (vencer / prueba) ────────────────────────────
        if (in_array($this->activeTab, ['vencer', 'prueba'])) {
            $filters[] = SelectFilter::make('days')
                ->label('Vencer en')
                ->options(['30' => '30 días', '60' => '60 días', '90' => '90 días'])
                ->placeholder('Todos')
                ->query(function (Builder $query, array $data) {
                    if (! filled($data['value'])) {
                        return $query;
                    }
                    $days = (int) $data['value'];
                    if ($this->activeTab === 'prueba') {
                        return $query->whereRaw(
                            'DATEDIFF(DATE_ADD(contracts.start_date, INTERVAL contracts.trial_days DAY), CURDATE()) <= ?',
                            [$days]
                        );
                    }

                    return $query
                        ->whereRaw('DATEDIFF(contracts.end_date, CURDATE()) >= 0')
                        ->whereRaw('DATEDIFF(contracts.end_date, CURDATE()) <= ?', [$days]);
                });
        }

        // ── Período de rescisión rápido (rescindidos) ────────────────────────
        if ($this->activeTab === 'rescindidos') {
            $filters[] = SelectFilter::make('period')
                ->label('Rescindidos en los últimos')
                ->options(['3' => '3 meses', '6' => '6 meses', '12' => '12 meses'])
                ->placeholder('Todos')
                ->query(function (Builder $query, array $data) {
                    if (! filled($data['value'])) {
                        return $query;
                    }

                    return $query->where('contracts.terminated_at', '>=', now()->subMonths((int) $data['value']));
                });
        }

        // ── Rango de fecha de inicio ─────────────────────────────────────────
        if (in_array($this->activeTab, ['vencer', 'activos', 'antiguedad', 'rescindidos'])) {
            $filters[] = Filter::make('start_date_range')
                ->label('Período de inicio')
                ->columnSpan(2)
                ->form([
                    DatePicker::make('start_from')->label('Inicio desde')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('start_until')->label('Inicio hasta')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->query(function (Builder $query, array $data): Builder {
                    if (filled($data['start_from'] ?? null)) {
                        $query->where('contracts.start_date', '>=', $data['start_from']);
                    }
                    if (filled($data['start_until'] ?? null)) {
                        $query->where('contracts.start_date', '<=', $data['start_until']);
                    }

                    return $query;
                })
                ->indicateUsing(function (array $data): ?string {
                    $parts = [];
                    if (filled($data['start_from'] ?? null)) {
                        $parts[] = 'desde '.Carbon::parse($data['start_from'])->format('d/m/Y');
                    }
                    if (filled($data['start_until'] ?? null)) {
                        $parts[] = 'hasta '.Carbon::parse($data['start_until'])->format('d/m/Y');
                    }

                    return $parts ? 'Inicio: '.implode(' — ', $parts) : null;
                });
        }

        // ── Rango de fecha de vencimiento (solo vencer) ──────────────────────
        if ($this->activeTab === 'vencer') {
            $filters[] = Filter::make('end_date_range')
                ->label('Período de vencimiento')
                ->columnSpan(2)
                ->form([
                    DatePicker::make('end_from')->label('Vence desde')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('end_until')->label('Vence hasta')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->query(function (Builder $query, array $data): Builder {
                    if (filled($data['end_from'] ?? null)) {
                        $query->where('contracts.end_date', '>=', $data['end_from']);
                    }
                    if (filled($data['end_until'] ?? null)) {
                        $query->where('contracts.end_date', '<=', $data['end_until']);
                    }

                    return $query;
                })
                ->indicateUsing(function (array $data): ?string {
                    $parts = [];
                    if (filled($data['end_from'] ?? null)) {
                        $parts[] = 'desde '.Carbon::parse($data['end_from'])->format('d/m/Y');
                    }
                    if (filled($data['end_until'] ?? null)) {
                        $parts[] = 'hasta '.Carbon::parse($data['end_until'])->format('d/m/Y');
                    }

                    return $parts ? 'Vencimiento: '.implode(' — ', $parts) : null;
                });
        }

        // ── Rango de registro en sistema (solo sin_contrato) ─────────────────
        if ($this->activeTab === 'sin_contrato') {
            $filters[] = Filter::make('created_at_range')
                ->label('Registrado en el sistema')
                ->columnSpan(2)
                ->form([
                    DatePicker::make('created_from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('created_until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->query(function (Builder $query, array $data): Builder {
                    if (filled($data['created_from'] ?? null)) {
                        $query->where('employees.created_at', '>=', Carbon::parse($data['created_from'])->startOfDay());
                    }
                    if (filled($data['created_until'] ?? null)) {
                        $query->where('employees.created_at', '<=', Carbon::parse($data['created_until'])->endOfDay());
                    }

                    return $query;
                })
                ->indicateUsing(function (array $data): ?string {
                    $parts = [];
                    if (filled($data['created_from'] ?? null)) {
                        $parts[] = 'desde '.Carbon::parse($data['created_from'])->format('d/m/Y');
                    }
                    if (filled($data['created_until'] ?? null)) {
                        $parts[] = 'hasta '.Carbon::parse($data['created_until'])->format('d/m/Y');
                    }

                    return $parts ? 'Registrado: '.implode(' — ', $parts) : null;
                });
        }

        // ── Rango de fecha de rescisión (solo rescindidos) ───────────────────
        if ($this->activeTab === 'rescindidos') {
            $filters[] = Filter::make('terminated_range')
                ->label('Período de rescisión')
                ->columnSpan(2)
                ->form([
                    DatePicker::make('terminated_from')->label('Rescindido desde')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('terminated_until')->label('Rescindido hasta')->native(false)->displayFormat('d/m/Y'),
                ])
                ->columns(2)
                ->query(function (Builder $query, array $data): Builder {
                    if (filled($data['terminated_from'] ?? null)) {
                        $query->where('contracts.terminated_at', '>=', $data['terminated_from']);
                    }
                    if (filled($data['terminated_until'] ?? null)) {
                        $query->where('contracts.terminated_at', '<=', $data['terminated_until']);
                    }

                    return $query;
                })
                ->indicateUsing(function (array $data): ?string {
                    $parts = [];
                    if (filled($data['terminated_from'] ?? null)) {
                        $parts[] = 'desde '.Carbon::parse($data['terminated_from'])->format('d/m/Y');
                    }
                    if (filled($data['terminated_until'] ?? null)) {
                        $parts[] = 'hasta '.Carbon::parse($data['terminated_until'])->format('d/m/Y');
                    }

                    return $parts ? 'Rescisión: '.implode(' — ', $parts) : null;
                });
        }

        return $filters;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Extrae los filtros activos de la sesión para pasarlos a las exportaciones.
     *
     * @return array<string, mixed>
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            'companyId' => filled($f['company_id']['value'] ?? null) ? (int) $f['company_id']['value'] : null,
            'branchId' => filled($f['branch_id']['value'] ?? null) ? (int) $f['branch_id']['value'] : null,
            'type' => filled($f['type']['value'] ?? null) ? $f['type']['value'] : null,
            'salaryType' => filled($f['salary_type']['value'] ?? null) ? $f['salary_type']['value'] : null,
            'departmentId' => filled($f['department_id']['value'] ?? null) ? (int) $f['department_id']['value'] : null,
            'positionId' => filled($f['position_id']['value'] ?? null) ? (int) $f['position_id']['value'] : null,
            'days' => filled($f['days']['value'] ?? null) ? (int) $f['days']['value'] : null,
            'period' => filled($f['period']['value'] ?? null) ? (int) $f['period']['value'] : null,
            'startDateFrom' => filled($f['start_date_range']['start_from'] ?? null) ? $f['start_date_range']['start_from'] : null,
            'startDateUntil' => filled($f['start_date_range']['start_until'] ?? null) ? $f['start_date_range']['start_until'] : null,
            'endDateFrom' => filled($f['end_date_range']['end_from'] ?? null) ? $f['end_date_range']['end_from'] : null,
            'endDateUntil' => filled($f['end_date_range']['end_until'] ?? null) ? $f['end_date_range']['end_until'] : null,
            'terminatedFrom' => filled($f['terminated_range']['terminated_from'] ?? null) ? $f['terminated_range']['terminated_from'] : null,
            'terminatedUntil' => filled($f['terminated_range']['terminated_until'] ?? null) ? $f['terminated_range']['terminated_until'] : null,
            'createdFrom' => filled($f['created_at_range']['created_from'] ?? null) ? $f['created_at_range']['created_from'] : null,
            'createdUntil' => filled($f['created_at_range']['created_until'] ?? null) ? $f['created_at_range']['created_until'] : null,
        ];
    }
}
