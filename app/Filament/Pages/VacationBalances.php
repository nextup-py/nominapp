<?php

namespace App\Filament\Pages;

use App\Filament\Resources\VacationResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\VacationBalance;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Muestra el saldo de días de vacaciones por empleado, filtrable por año y sucursal. */
class VacationBalances extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return VacationResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Balances de Vacaciones';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.vacation-balances';

    protected ?string $heading = 'Balances de Vacaciones';

    public function mount(): void
    {
        $this->tableFilters['year']['value'] ??= (string) now()->year;
    }

    /** Subheading dinámico con el año activo del filtro. */
    public function getSubheading(): ?string
    {
        $year = $this->tableFilters['year']['value'] ?? now()->year;

        return 'Saldo de días por empleado · Año '.$year;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateBalances')
                ->label('Generar Balances')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Generar Balances de Vacaciones')
                ->modalDescription('Se generarán los balances para todos los empleados activos que aún no tengan balance en el año seleccionado.')
                ->modalSubmitActionLabel('Sí, generar')
                ->form([
                    Select::make('year')
                        ->label('Año')
                        ->options(function () {
                            $currentYear = now()->year;

                            return [
                                $currentYear - 1 => $currentYear - 1,
                                $currentYear => $currentYear.' (actual)',
                                $currentYear + 1 => $currentYear + 1,
                            ];
                        })
                        ->default(now()->year)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    Artisan::call('vacations:generate-annual-balances', [
                        '--year' => $data['year'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Balances generados')
                        ->body('El proceso finalizó. Revisá la tabla para ver los resultados.')
                        ->send();
                }),
        ];
    }

    /** Define la tabla de balances con filtros y columnas. */
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
                    ->sortable()
                    ->visible(fn () => Branch::whereHas('company', fn ($q) => $q->active())->count() > 1),

                TextColumn::make('years_of_service')
                    ->label('Antigüedad')
                    ->formatStateUsing(fn ($state) => $state.' '.($state === 1 ? 'año' : 'años'))
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('entitled_days')
                    ->label('Con Derecho')
                    ->suffix(' días')
                    ->badge()
                    ->color('primary')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('used_days')
                    ->label('Usados')
                    ->suffix(' días')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('pending_days')
                    ->label('Pendientes')
                    ->suffix(' días')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('available_days')
                    ->label('Disponibles')
                    ->suffix(' días')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->emptyStateHeading('Sin balances para el año')
            ->emptyStateDescription('Usá el botón "Generar Balances" para crear los saldos de los empleados activos.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    /**
     * Query base: VacationBalance con joins a employees y branches.
     * available_days se calcula en SQL para permitir ordenamiento.
     */
    private function buildQuery(): Builder
    {
        return VacationBalance::query()
            ->select([
                'employee_vacation_balances.id',
                'employee_vacation_balances.employee_id',
                'employee_vacation_balances.year',
                'employee_vacation_balances.years_of_service',
                'employee_vacation_balances.entitled_days',
                'employee_vacation_balances.used_days',
                'employee_vacation_balances.pending_days',
                DB::raw('GREATEST(0, CAST(employee_vacation_balances.entitled_days AS SIGNED) - CAST(employee_vacation_balances.used_days AS SIGNED) - CAST(employee_vacation_balances.pending_days AS SIGNED)) AS available_days'),
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                'branches.name AS branch_name',
            ])
            ->join('employees', 'employees.id', '=', 'employee_vacation_balances.employee_id')
            ->join('branches', 'branches.id', '=', 'employees.branch_id');
    }

    /**
     * Filtros: año (requerido), empresa (si hay varias) y sucursal.
     *
     * @return array<int, mixed>
     */
    private function buildFilters(): array
    {
        $yearOptions = collect(range(now()->year - 3, now()->year + 1))
            ->mapWithKeys(fn ($y) => [$y => (string) $y])
            ->all();

        $filters = [
            SelectFilter::make('year')
                ->label('Año')
                ->options($yearOptions)
                ->default((string) now()->year)
                ->query(fn (Builder $query, array $data) => filled($data['value'])
                    ? $query->where('employee_vacation_balances.year', $data['value'])
                    : $query->where('employee_vacation_balances.year', now()->year)
                ),
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

        $filters[] = SelectFilter::make('branch_id')
            ->label('Sucursal')
            ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->query(fn (Builder $query, array $data) => $data['value']
                ? $query->where('employees.branch_id', $data['value'])
                : $query
            );

        return $filters;
    }
}
