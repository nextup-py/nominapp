<?php

namespace App\Filament\Pages;

use App\Exports\MerchandiseReportExport;
use App\Exports\MerchandiseWithdrawalsSheet;
use App\Filament\Resources\MerchandiseWithdrawalResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MerchandiseWithdrawal;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
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
 * Reporte de retiros de mercadería: resumen por retiro con cuotas anidadas en el export.
 *
 * La tabla muestra un renglón por MerchandiseWithdrawal. El Excel tiene dos hojas:
 * "Retiros" (resumen) y "Cuotas" (detalle por cuota).
 */
class MerchandiseReport extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return MerchandiseWithdrawalResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Reporte de Mercaderías';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.merchandise-report';

    protected ?string $heading = 'Reporte de Retiro de Mercaderías';

    /**
     * Retorna el subheading dinámico con el período y filtros activos.
     */
    public function getSubheading(): ?string
    {
        $f = $this->tableFilters ?? [];
        $from = $f['period']['from_date'] ?? null;
        $to = $f['period']['to_date'] ?? null;
        $status = isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null;

        if ($from && $to) {
            $base = 'Retiros del '.date('d/m/Y', strtotime($from)).' al '.date('d/m/Y', strtotime($to));
        } elseif ($from) {
            $base = 'Retiros desde el '.date('d/m/Y', strtotime($from));
        } else {
            $base = 'Retiros del mes '.now()->translatedFormat('F Y');
        }

        return $status ? $base.' · '.MerchandiseWithdrawal::getStatusOptions()[$status] : $base;
    }

    /**
     * Acciones de exportación (PDF y Excel) con los filtros activos.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $columnOptions = MerchandiseWithdrawalsSheet::availableColumns();
        $columnDefaults = MerchandiseWithdrawalsSheet::defaultColumns();
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
                    [$from, $to, $companyId, $branchId, $status, $employeeId] = $this->resolveActiveFilters();

                    $params = array_filter([
                        'from' => $from,
                        'to' => $to,
                        'companyId' => $companyId,
                        'branchId' => $branchId,
                        'status' => $status,
                        'employeeId' => $employeeId,
                        'columns' => implode(',', $data['columns']),
                        'orientation' => $data['orientation'] ?? 'landscape',
                    ], fn ($v) => $v !== null);

                    $url = route('merchandise.report.pdf', $params);
                    $this->js("window.open('".addslashes($url)."', '_blank')");
                }),

            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->modalHeading('Exportar reporte de mercaderías')
                ->modalDescription('Seleccione las columnas de la hoja "Retiros". La hoja "Cuotas" siempre se exporta completa.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas de la hoja Retiros')
                        ->options($columnOptions)
                        ->default($columnDefaults)
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $status, $employeeId] = $this->resolveActiveFilters();

                    Notification::make()
                        ->success()
                        ->title('Exportación iniciada')
                        ->body('El archivo se descargará en breve.')
                        ->send();

                    return Excel::download(
                        new MerchandiseReportExport($from, $to, $companyId, $branchId, $status, $employeeId, $data['columns']),
                        'retiros_mercaderia_'.now()->format('Y_m_d_H_i').'.xlsx'
                    );
                }),
        ];
    }

    /**
     * Define la tabla del reporte con filtros y columnas.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->filters($this->buildFilters(), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->persistFiltersInSession()
            ->defaultSort('employees.last_name', 'asc')
            ->paginated([25, 50, 100])
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
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->badge()
                    ->color(fn ($record) => MerchandiseWithdrawal::getStatusColor($record->status))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('installments_progress')
                    ->label('Cuotas')
                    ->getStateUsing(fn ($record) => $record->paid_installments_count.'/'.$record->installments_count.' pagadas')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('outstanding_balance')
                    ->label('Saldo pendiente')
                    ->formatStateUsing(fn ($state) => 'Gs. '.number_format((float) $state, 0, ',', '.'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => MerchandiseWithdrawal::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => MerchandiseWithdrawal::getStatusColor($state))
                    ->icon(fn ($state) => MerchandiseWithdrawal::getStatusIcon($state)),

                TextColumn::make('approved_at')
                    ->label('Aprobado el')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('approved_by_name')
                    ->label('Aprobado por')
                    ->getStateUsing(fn ($record) => $record->approved_by_name ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Sin retiros para el período')
            ->emptyStateDescription('No hay retiros de mercadería para los filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-shopping-bag');
    }

    /**
     * Query base: MerchandiseWithdrawal con joins a employees, branches, companies y aprobador.
     */
    private function buildQuery(): Builder
    {
        return MerchandiseWithdrawal::query()
            ->select([
                'merchandise_withdrawals.id',
                'merchandise_withdrawals.employee_id',
                'merchandise_withdrawals.total_amount',
                'merchandise_withdrawals.installments_count',
                'merchandise_withdrawals.installment_amount',
                'merchandise_withdrawals.outstanding_balance',
                'merchandise_withdrawals.status',
                'merchandise_withdrawals.notes',
                'merchandise_withdrawals.approved_at',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name as branch_name',
                'companies.id as company_id',
                'companies.name as company_name',
                'approvers.name as approved_by_name',
                DB::raw('(SELECT COUNT(*) FROM merchandise_withdrawal_installments
                          WHERE merchandise_withdrawal_id = merchandise_withdrawals.id
                          AND status = "paid") AS paid_installments_count'),
            ])
            ->join('employees', 'employees.id', '=', 'merchandise_withdrawals.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->join('companies', 'companies.id', '=', 'branches.company_id')
            ->leftJoin('users as approvers', 'approvers.id', '=', 'merchandise_withdrawals.approved_by_id');
    }

    /**
     * Filtros del reporte: período, empresa, sucursal, estado y empleado.
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $filters = [
            Filter::make('period')
                ->label('Período')
                ->form([
                    DatePicker::make('from_date')
                        ->label('Desde')
                        ->default(now()->startOfMonth()),
                    DatePicker::make('to_date')
                        ->label('Hasta')
                        ->default(now()->endOfMonth()),
                ])
                ->columns(2)
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['from_date'] ?? null, fn ($q, $v) => $q->whereDate('merchandise_withdrawals.created_at', '>=', $v))
                    ->when($data['to_date'] ?? null, fn ($q, $v) => $q->whereDate('merchandise_withdrawals.created_at', '<=', $v))
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
                }),
        ];

        if (Company::active()->count() > 1) {
            $filters[] = SelectFilter::make('company_id')
                ->label('Empresa')
                ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->query(fn (Builder $query, array $data) => $data['value']
                    ? $query->where('branches.company_id', $data['value'])
                    : $query
                );
        }

        return array_merge($filters, [
            SelectFilter::make('branch_id')
                ->label('Sucursal')
                ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->query(fn (Builder $query, array $data) => $data['value']
                    ? $query->where('employees.branch_id', $data['value'])
                    : $query
                ),

            SelectFilter::make('status')
                ->label('Estado')
                ->options(MerchandiseWithdrawal::getStatusOptions())
                ->placeholder('Todos los estados')
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('merchandise_withdrawals.status', $data['value'])
                    : $query
                ),

            SelectFilter::make('employee_id')
                ->label('Empleado')
                ->options(fn () => Employee::orderBy('last_name')
                    ->orderBy('first_name')
                    ->get()
                    ->mapWithKeys(fn ($e) => [$e->id => $e->last_name.', '.$e->first_name.' ('.$e->ci.')'])
                    ->all()
                )
                ->searchable()
                ->query(fn (Builder $query, array $data) => $data['value']
                    ? $query->where('merchandise_withdrawals.employee_id', $data['value'])
                    : $query
                ),
        ]);
    }

    /**
     * Extrae los valores activos de los filtros para pasarlos al export/PDF.
     *
     * @return array{string|null, string|null, int|null, int|null, string|null, int|null}
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            $f['period']['from_date'] ?? now()->startOfMonth()->toDateString(),
            $f['period']['to_date'] ?? now()->endOfMonth()->toDateString(),
            isset($f['company_id']['value']) && $f['company_id']['value'] !== '' ? (int) $f['company_id']['value'] : null,
            isset($f['branch_id']['value']) && $f['branch_id']['value'] !== '' ? (int) $f['branch_id']['value'] : null,
            isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null,
            isset($f['employee_id']['value']) && $f['employee_id']['value'] !== '' ? (int) $f['employee_id']['value'] : null,
        ];
    }
}
