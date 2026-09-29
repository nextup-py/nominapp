<?php

namespace App\Filament\Pages;

use App\Exports\LoanReportExport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Loan;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
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
 * Reporte de préstamos: muestra cada préstamo individualmente,
 * filtrable por estado, empresa, sucursal, empleado y rango de fechas de otorgamiento.
 */
class LoanReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Reporte de Préstamos';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.loan-report';

    protected ?string $heading = 'Reporte de Préstamos';

    /**
     * Retorna el subheading dinámico con los filtros activos.
     */
    public function getSubheading(): ?string
    {
        $f = $this->tableFilters ?? [];
        $from = $f['period']['from_date'] ?? null;
        $to = $f['period']['to_date'] ?? null;
        $status = isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null;

        if ($from && $to) {
            $base = 'Otorgados del '.date('d/m/Y', strtotime($from)).' al '.date('d/m/Y', strtotime($to));
        } elseif ($from) {
            $base = 'Otorgados desde el '.date('d/m/Y', strtotime($from));
        } else {
            $base = 'Todos los préstamos';
        }

        if ($status) {
            $base .= ' · '.Loan::getStatusLabel($status);
        }

        return $base;
    }

    /**
     * Acciones de exportación (Excel) con selector de columnas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $columnOptions = LoanReportExport::availableColumns();
        $columnDefaults = LoanReportExport::defaultColumns();
        $orientationThreshold = 8;

        if (Company::active()->count() <= 1) {
            unset($columnOptions['company_name']);
            $columnDefaults = array_values(array_diff($columnDefaults, ['company_name']));
        }

        if (Branch::whereHas('company', fn ($q) => $q->active())->count() <= 1) {
            unset($columnOptions['branch_name']);
            $columnDefaults = array_values(array_diff($columnDefaults, ['branch_name']));
        }

        return [
            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->modalHeading('Exportar reporte de préstamos')
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
                    [$companyId, $branchId, $status, $employeeId, $from, $to, $paymentMethod] = $this->resolveActiveFilters();

                    Notification::make()
                        ->success()
                        ->title('Exportación iniciada')
                        ->body('El archivo se descargará en breve.')
                        ->send();

                    return Excel::download(
                        new LoanReportExport($data['columns'], [
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'status' => $status,
                            'employee_id' => $employeeId,
                            'from' => $from,
                            'to' => $to,
                            'payment_method' => $paymentMethod,
                        ]),
                        'prestamos_'.now()->format('Y_m_d_H_i').'.xlsx'
                    );
                }),
        ];
    }

    /**
     * Define la tabla del reporte con filtros y columnas.
     */
    public function table(Table $table): Table
    {
        $multipleCompanies = Company::active()->count() > 1;
        $multipleBranches = Branch::whereHas('company', fn ($q) => $q->active())->count() > 1;

        return $table
            ->query($this->buildQuery())
            ->filters($this->buildFilters(), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->persistFiltersInSession()
            ->defaultSort('employees.last_name', 'asc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->striped()
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Empleado')
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
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('company_name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible($multipleCompanies),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' Gs.')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('installments_count')
                    ->label('Cuotas')
                    ->getStateUsing(fn ($record) => "{$record->paid_installments_count}/{$record->installments_count}")
                    ->badge()
                    ->color('gray'),

                TextColumn::make('installment_amount')
                    ->label('Cuota Mensual')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' Gs.')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('outstanding_balance')
                    ->label('Saldo Pendiente')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' Gs.')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Loan::getPaymentMethodLabel($state ?? 'cash'))
                    ->color(fn ($state) => Loan::getPaymentMethodColor($state ?? 'cash'))
                    ->icon(fn ($state) => Loan::getPaymentMethodIcon($state ?? 'cash'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => Loan::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => Loan::getStatusColor($state))
                    ->icon(fn ($state) => Loan::getStatusIcon($state)),

                TextColumn::make('granted_at')
                    ->label('Fecha Otorgamiento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('granted_by_name')
                    ->label('Aprobado por')
                    ->getStateUsing(fn ($record) => $record->granted_by_name ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Sin préstamos para los filtros seleccionados')
            ->emptyStateDescription('No hay registros de préstamos para los filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-credit-card');
    }

    /**
     * Query base: Loan con joins a employees, branches, companies y users (otorgante).
     */
    private function buildQuery(): Builder
    {
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
            ->leftJoin('users', 'loans.granted_by_id', '=', 'users.id');
    }

    /**
     * Filtros del reporte: estado, empresa, sucursal, empleado y rango de fechas.
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $filters = [
            SelectFilter::make('status')
                ->label('Estado')
                ->options(Loan::getStatusOptions())
                ->placeholder('Todos los estados')
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('loans.status', $data['value'])
                    : $query
                ),
        ];

        if (Company::active()->count() > 1) {
            $filters[] = Filter::make('company_id')
                ->label('Empresa')
                ->form([
                    FormSelect::make('value')
                        ->label('Empresa')
                        ->options(fn () => Company::orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->placeholder('Todas')
                        ->live(),
                ])
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('branches.company_id', (int) $data['value'])
                    : $query
                )
                ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                    ? 'Empresa: '.Company::find($data['value'])?->name
                    : null
                );
        }

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
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('employees.branch_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                ? 'Sucursal: '.Branch::find($data['value'])?->name
                : null
            );

        $filters[] = Filter::make('employee_id')
            ->label('Empleado')
            ->form([
                FormSelect::make('value')
                    ->label('Empleado')
                    ->options(function () {
                        $branchId = $this->tableFilters['branch_id']['value'] ?? null;
                        $companyId = $this->tableFilters['company_id']['value'] ?? null;

                        return Employee::where('status', 'active')
                            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                            ->when($companyId && ! $branchId, fn ($q) => $q->whereHas(
                                'branch', fn ($b) => $b->where('company_id', $companyId)
                            ))
                            ->orderBy('last_name')->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn ($e) => [$e->id => $e->last_name.', '.$e->first_name.' (CI: '.$e->ci.')'])
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Todos')
                    ->live(),
            ])
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('loans.employee_id', (int) $data['value'])
                : $query
            )
            ->indicateUsing(fn (array $data): ?string => filled($data['value'])
                ? 'Empleado: '.Employee::find($data['value'])?->full_name
                : null
            );

        $filters[] = SelectFilter::make('payment_method')
            ->label('Método de pago')
            ->options(Loan::getPaymentMethodOptions())
            ->placeholder('Todos')
            ->query(fn (Builder $query, array $data) => filled($data['value'])
                ? $query->where('loans.payment_method', $data['value'])
                : $query
            );

        $filters[] = Filter::make('period')
            ->label('Período')
            ->form([
                DatePicker::make('from_date')
                    ->label('Desde'),
                DatePicker::make('to_date')
                    ->label('Hasta'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from_date'] ?? null, fn ($q, $v) => $q->whereDate('loans.granted_at', '>=', $v))
                ->when($data['to_date'] ?? null, fn ($q, $v) => $q->whereDate('loans.granted_at', '<=', $v))
            )
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                if ($data['from_date'] ?? null) {
                    $indicators[] = 'Desde: '.date('d/m/Y', strtotime($data['from_date']));
                }
                if ($data['to_date'] ?? null) {
                    $indicators[] = 'Hasta: '.date('d/m/Y', strtotime($data['to_date']));
                }

                return $indicators;
            });

        return $filters;
    }

    /**
     * Livewire hook: limpia filtros hijos al cambiar empresa o sucursal.
     */
    public function updated(string $name): void
    {
        if ($name === 'tableFilters.company_id.value') {
            $this->tableFilters['branch_id']['value'] = '';
            $this->tableFilters['employee_id']['value'] = '';
        }

        if ($name === 'tableFilters.branch_id.value') {
            $this->tableFilters['employee_id']['value'] = '';
        }
    }

    /**
     * Extrae los valores activos de los filtros para pasarlos al export.
     *
     * @return array{int|null, int|null, string|null, int|null, string|null, string|null}
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            isset($f['company_id']['value']) && $f['company_id']['value'] !== '' ? (int) $f['company_id']['value'] : null,
            isset($f['branch_id']['value']) && $f['branch_id']['value'] !== '' ? (int) $f['branch_id']['value'] : null,
            isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null,
            isset($f['employee_id']['value']) && $f['employee_id']['value'] !== '' ? (int) $f['employee_id']['value'] : null,
            $f['period']['from_date'] ?? null,
            $f['period']['to_date'] ?? null,
            isset($f['payment_method']['value']) && $f['payment_method']['value'] !== '' ? $f['payment_method']['value'] : null,
        ];
    }
}
