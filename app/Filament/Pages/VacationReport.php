<?php

namespace App\Filament\Pages;

use App\Exports\VacationReportExport;
use App\Filament\Resources\VacationResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Vacation;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte de vacaciones individuales: muestra cada período de vacación tomado
 * por empleado, filtrable por año, mes, empresa, sucursal y estado.
 */
class VacationReport extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return VacationResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'Reporte de Vacaciones';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.vacation-report';

    protected ?string $heading = 'Reporte de Vacaciones';

    /** @var array<string, string> Nombres de meses en español. */
    private const MONTHS = [
        '1' => 'Enero',      '2' => 'Febrero',   '3' => 'Marzo',
        '4' => 'Abril',      '5' => 'Mayo',       '6' => 'Junio',
        '7' => 'Julio',      '8' => 'Agosto',     '9' => 'Septiembre',
        '10' => 'Octubre',    '11' => 'Noviembre',  '12' => 'Diciembre',
    ];

    /**
     * Retorna el subheading dinámico con el año y mes activos del filtro.
     */
    public function getSubheading(): ?string
    {
        $f = $this->tableFilters ?? [];
        $year = $f['year']['value'] ?? now()->year;
        $month = $f['month']['value'] ?? null;

        if ($month && isset(self::MONTHS[$month])) {
            return 'Períodos de vacación · '.self::MONTHS[$month].' '.$year;
        }

        return 'Períodos de vacación · Año '.$year;
    }

    /**
     * Acciones de exportación (PDF y Excel) con selector de columnas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $columnOptions = VacationReportExport::availableColumns();
        $columnDefaults = VacationReportExport::defaultColumns();
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
                    [$year, $month, $companyId, $branchId, $status] = $this->resolveActiveFilters();

                    $params = array_filter([
                        'year' => $year,
                        'month' => $month,
                        'companyId' => $companyId,
                        'branchId' => $branchId,
                        'status' => $status,
                        'columns' => implode(',', $data['columns']),
                        'orientation' => $data['orientation'] ?? 'portrait',
                    ], fn ($v) => $v !== null);

                    $url = route('vacation.report.pdf', $params);
                    $this->js("window.open('".addslashes($url)."', '_blank')");
                }),

            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->modalHeading('Exportar reporte de vacaciones')
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
                    [$year, $month, $companyId, $branchId, $status] = $this->resolveActiveFilters();

                    Notification::make()
                        ->success()
                        ->title('Exportación iniciada')
                        ->body('El archivo se descargará en breve.')
                        ->send();

                    $suffix = $month ? '_'.str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '';

                    return Excel::download(
                        new VacationReportExport($year, $month, $companyId, $branchId, $status, $data['columns']),
                        'vacaciones_'.$year.$suffix.'_'.now()->format('Y_m_d_H_i').'.xlsx'
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

                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('business_days')
                    ->label('Días hábiles')
                    ->suffix(' días')
                    ->badge()
                    ->color('primary')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('payment_amount')
                    ->label('Monto')
                    ->formatStateUsing(fn ($state) => ($state !== null && $state > 0)
                        ? 'Gs. '.number_format((float) $state, 0, ',', '.')
                        : '—')
                    ->badge()
                    ->color(fn ($state) => ($state !== null && $state > 0) ? 'success' : 'gray')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Forma de pago')
                    ->formatStateUsing(fn ($state) => Vacation::getPaymentMethodLabel($state))
                    ->badge()
                    ->color(fn ($state) => Vacation::getPaymentMethodColor($state))
                    ->icon(fn ($state) => Vacation::getPaymentMethodIcon($state)),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => Vacation::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => Vacation::getStatusColor($state))
                    ->icon(fn ($state) => Vacation::getStatusIcon($state)),
            ])
            ->emptyStateHeading('Sin vacaciones para el período')
            ->emptyStateDescription('No hay registros de vacaciones para los filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-sun');
    }

    /**
     * Query base: Vacation con joins a employees y branches.
     */
    private function buildQuery(): Builder
    {
        return Vacation::query()
            ->select([
                'vacations.id',
                'vacations.employee_id',
                'vacations.start_date',
                'vacations.end_date',
                'vacations.business_days',
                'vacations.payment_method',
                'vacations.status',
                'vacations.payment_amount',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name as branch_name',
            ])
            ->join('employees', 'employees.id', '=', 'vacations.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id');
    }

    /**
     * Filtros del reporte: año, mes, empresa, sucursal y estado.
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $yearOptions = collect(range(now()->year - 2, now()->year + 1))
            ->mapWithKeys(fn ($y) => [$y => (string) $y])
            ->all();

        return [
            SelectFilter::make('year')
                ->label('Año')
                ->options($yearOptions)
                ->default((string) now()->year)
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->whereYear('vacations.start_date', $data['value'])
                    : $query->whereYear('vacations.start_date', now()->year)
                ),

            SelectFilter::make('month')
                ->label('Mes')
                ->options(self::MONTHS)
                ->placeholder('Todos los meses')
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->whereMonth('vacations.start_date', $data['value'])
                    : $query
                ),

            ...(Company::active()->count() > 1 ? [
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->where('branches.company_id', $data['value'])
                        : $query
                    ),
            ] : []),

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
                ->options(Vacation::getStatusOptions())
                ->placeholder('Todos los estados')
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('vacations.status', $data['value'])
                    : $query
                ),
        ];
    }

    /**
     * Extrae los valores activos de los filtros para pasarlos al export.
     *
     * @return array{int, int|null, int|null, int|null, string|null}
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            isset($f['year']['value']) ? (int) $f['year']['value'] : now()->year,
            isset($f['month']['value']) && $f['month']['value'] !== '' ? (int) $f['month']['value'] : null,
            isset($f['company_id']['value']) && $f['company_id']['value'] !== '' ? (int) $f['company_id']['value'] : null,
            isset($f['branch_id']['value']) && $f['branch_id']['value'] !== '' ? (int) $f['branch_id']['value'] : null,
            isset($f['status']['value']) && $f['status']['value'] !== '' ? $f['status']['value'] : null,
        ];
    }
}
