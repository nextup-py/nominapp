<?php

namespace App\Filament\Pages;

use App\Exports\AbsenceReportDetailExport;
use App\Exports\AbsenceReportSummaryExport;
use App\Exports\AttendanceReportDetailExport;
use App\Exports\AttendanceReportSummaryExport;
use App\Exports\OvertimeReportDetailExport;
use App\Exports\OvertimeReportSummaryExport;
use App\Filament\Resources\AttendanceDayResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
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
 * Página unificada de reportes de asistencias y ausencias por período.
 *
 * Presenta dos tabs: "Asistencias" (resumen de marcaciones por empleado)
 * y "Ausencias" (resumen del flujo de gestión de ausencias por empleado).
 * Los filtros de período, empresa, sucursal y departamento son compartidos.
 */
class AttendanceReport extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return AttendanceDayResource::canViewAny();
    }

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reporte de Asistencia';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.attendance-report';

    protected ?string $heading = 'Reportes de Asistencia';

    /** @var string Tab activo: 'attendance', 'absence' o 'overtime'. */
    public string $activeTab = 'attendance';

    /**
     * Cambia el tab activo y reinicia la tabla para aplicar la nueva query y columnas.
     *
     * @param  string  $tab  'attendance' | 'absence'
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }

    /**
     * Retorna el subheading dinámico con el tab activo y los filtros vigentes.
     */
    public function getSubheading(): ?string
    {
        $f = $this->tableFilters ?? [];

        $from = $f['period']['from_date'] ?? null;
        $to = $f['period']['to_date'] ?? null;
        $branchId = isset($f['branch_id']['value']) && $f['branch_id']['value'] !== '' ? (int) $f['branch_id']['value'] : null;
        $companyId = isset($f['company_id']['value']) && $f['company_id']['value'] !== '' ? (int) $f['company_id']['value'] : null;
        $deptId = isset($f['department_id']['value']) && $f['department_id']['value'] !== '' ? (int) $f['department_id']['value'] : null;
        $employeeId = isset($f['employee_id']['value']) && $f['employee_id']['value'] !== '' ? (int) $f['employee_id']['value'] : null;

        $parts = [];

        if ($from || $to) {
            $period = '';
            if ($from) {
                $period .= Carbon::parse($from)->format('d/m/Y');
            }
            if ($from && $to) {
                $period .= ' al ';
            }
            if ($to) {
                $period .= Carbon::parse($to)->format('d/m/Y');
            }
            $parts[] = $period;
        }

        if ($employeeId) {
            $emp = Employee::find($employeeId);
            if ($emp) {
                $parts[] = $emp->last_name.', '.$emp->first_name;
            }
        } else {
            if ($branchId) {
                $branch = Branch::find($branchId);
                if ($branch) {
                    $parts[] = $branch->name;
                }
            } elseif ($companyId) {
                $company = Company::find($companyId);
                if ($company) {
                    $parts[] = $company->name;
                }
            }
            if ($deptId) {
                $dept = Department::find($deptId);
                if ($dept) {
                    $parts[] = $dept->name;
                }
            }
        }

        $tabLabel = match ($this->activeTab) {
            'attendance' => 'Asistencias',
            'absence' => 'Ausencias',
            'overtime' => 'Horas Extras y Tardanzas',
            default => '',
        };

        return $parts
            ? $tabLabel.' · '.implode(' · ', $parts)
            : $tabLabel.' · Todos los empleados';
    }

    /**
     * Define las acciones del encabezado — todas siempre registradas, visibles según tab activo.
     */
    protected function getHeaderActions(): array
    {
        $orientationThreshold = 7;
        $singleBranch = Branch::whereHas('company', fn ($q) => $q->active())->count() <= 1;

        return [
            // ─── ASISTENCIAS ──────────────────────────────────────────────────────
            Action::make('pdf_attendance')
                ->label('Exportar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'attendance')
                ->modalHeading('Exportar asistencias en PDF')
                ->modalSubmitActionLabel('Generar PDF')
                ->form(function () use ($singleBranch, $orientationThreshold) {
                    $cols = array_filter([
                        'ci' => 'CI',
                        'branch_name' => $singleBranch ? null : 'Sucursal',
                        'department_name' => 'Departamento',
                        'days_present' => 'Presentes',
                        'days_absent' => 'Ausencias',
                        'days_leave' => 'Licencias',
                        'total_net_hours' => 'Horas Netas',
                        'total_anomalies' => 'Anomalías',
                    ]);
                    $defaults = array_keys($cols);

                    return [
                        CheckboxList::make('columns')
                            ->label('Columnas del resumen')
                            ->options($cols)
                            ->default($defaults)
                            ->columns(3)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) use ($orientationThreshold) {
                                $set('orientation', count($get('columns') ?? []) <= $orientationThreshold ? 'portrait' : 'landscape');
                            }),
                        Radio::make('orientation')
                            ->label('Orientación')
                            ->helperText('Se ajusta automáticamente según la cantidad de columnas seleccionadas.')
                            ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
                            ->default(count($defaults) > $orientationThreshold ? 'landscape' : 'portrait')
                            ->inline()
                            ->required(),
                    ];
                })
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    $params = array_filter(['from' => $from, 'to' => $to, 'companyId' => $companyId, 'branchId' => $branchId, 'deptId' => $deptId, 'employeeId' => $employeeId]);
                    $params['columns'] = implode(',', $data['columns']);
                    $params['orientation'] = $data['orientation'] ?? 'landscape';
                    $this->js("window.open('".addslashes(route('attendance.report.attendance.pdf', $params))."', '_blank')");
                }),

            Action::make('export_attendance_summary')
                ->label('Exportar Resumen')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'attendance')
                ->modalHeading('Exportar resumen de asistencias')
                ->modalDescription('Seleccione las columnas a incluir. Una fila por empleado con los totales del período.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(AttendanceReportSummaryExport::availableColumns())
                        ->default(AttendanceReportSummaryExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new AttendanceReportSummaryExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'asistencia_resumen_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),

            Action::make('export_attendance_detail')
                ->label('Exportar Detalle')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'attendance')
                ->modalHeading('Exportar detalle de asistencias')
                ->modalDescription('Seleccione las columnas a incluir. Una fila por día por empleado.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(AttendanceReportDetailExport::availableColumns())
                        ->default(AttendanceReportDetailExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new AttendanceReportDetailExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'asistencia_detalle_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),

            // ─── HORAS EXTRAS Y TARDANZAS ─────────────────────────────────────────
            Action::make('pdf_overtime')
                ->label('Exportar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'overtime')
                ->modalHeading('Exportar horas extras y tardanzas en PDF')
                ->modalSubmitActionLabel('Generar PDF')
                ->form(function () use ($singleBranch, $orientationThreshold) {
                    $cols = array_filter([
                        'ci' => 'CI',
                        'branch_name' => $singleBranch ? null : 'Sucursal',
                        'department_name' => 'Departamento',
                        'total_extra_hours' => 'HE Total (h)',
                        'total_extra_diurnas' => 'HE Diurnas (h)',
                        'total_extra_nocturnas' => 'HE Nocturnas (h)',
                        'days_with_extras' => 'Días con HE',
                        'days_approved' => 'HE Aprobados',
                        'total_late_minutes' => 'Tardanza Total',
                        'days_late' => 'Días Tarde',
                        'avg_late_minutes' => 'Prom. Tardanza',
                    ]);
                    $defaults = array_keys($cols);

                    return [
                        CheckboxList::make('columns')
                            ->label('Columnas del resumen')
                            ->options($cols)
                            ->default($defaults)
                            ->columns(3)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) use ($orientationThreshold) {
                                $set('orientation', count($get('columns') ?? []) <= $orientationThreshold ? 'portrait' : 'landscape');
                            }),
                        Radio::make('orientation')
                            ->label('Orientación')
                            ->helperText('Se ajusta automáticamente según la cantidad de columnas seleccionadas.')
                            ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
                            ->default(count($defaults) > $orientationThreshold ? 'landscape' : 'portrait')
                            ->inline()
                            ->required(),
                    ];
                })
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    $params = array_filter(['from' => $from, 'to' => $to, 'companyId' => $companyId, 'branchId' => $branchId, 'deptId' => $deptId, 'employeeId' => $employeeId]);
                    $params['columns'] = implode(',', $data['columns']);
                    $params['orientation'] = $data['orientation'] ?? 'landscape';
                    $this->js("window.open('".addslashes(route('attendance.report.overtime.pdf', $params))."', '_blank')");
                }),

            Action::make('export_overtime_summary')
                ->label('Exportar Resumen')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'overtime')
                ->modalHeading('Exportar resumen de horas extras y tardanzas')
                ->modalDescription('Seleccione las columnas a incluir. Una fila por empleado con los totales del período.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(OvertimeReportSummaryExport::availableColumns())
                        ->default(OvertimeReportSummaryExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new OvertimeReportSummaryExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'extras_tardanzas_resumen_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),

            Action::make('export_overtime_detail')
                ->label('Exportar Detalle')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'overtime')
                ->modalHeading('Exportar detalle de horas extras y tardanzas')
                ->modalDescription('Seleccione las columnas a incluir. Solo días con horas extras o tardanza.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(OvertimeReportDetailExport::availableColumns())
                        ->default(OvertimeReportDetailExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new OvertimeReportDetailExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'extras_tardanzas_detalle_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),

            // ─── AUSENCIAS ────────────────────────────────────────────────────────
            Action::make('pdf_absence')
                ->label('Exportar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'absence')
                ->modalHeading('Exportar ausencias en PDF')
                ->modalSubmitActionLabel('Generar PDF')
                ->form(function () use ($singleBranch, $orientationThreshold) {
                    $cols = array_filter([
                        'ci' => 'CI',
                        'branch_name' => $singleBranch ? null : 'Sucursal',
                        'department_name' => 'Departamento',
                        'total_absences' => 'Total',
                        'total_pending' => 'Pendientes',
                        'total_justified' => 'Justificadas',
                        'total_unjustified' => 'Injustificadas',
                        'total_deduction_amount' => 'Deducciones (Gs.)',
                    ]);
                    $defaults = array_keys($cols);

                    return [
                        CheckboxList::make('columns')
                            ->label('Columnas del resumen')
                            ->options($cols)
                            ->default($defaults)
                            ->columns(3)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set) use ($orientationThreshold) {
                                $set('orientation', count($get('columns') ?? []) <= $orientationThreshold ? 'portrait' : 'landscape');
                            }),
                        Radio::make('orientation')
                            ->label('Orientación')
                            ->helperText('Se ajusta automáticamente según la cantidad de columnas seleccionadas.')
                            ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
                            ->default(count($defaults) > $orientationThreshold ? 'landscape' : 'portrait')
                            ->inline()
                            ->required(),
                    ];
                })
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    $params = array_filter(['from' => $from, 'to' => $to, 'companyId' => $companyId, 'branchId' => $branchId, 'deptId' => $deptId, 'employeeId' => $employeeId]);
                    $params['columns'] = implode(',', $data['columns']);
                    $params['orientation'] = $data['orientation'] ?? 'portrait';
                    $this->js("window.open('".addslashes(route('attendance.report.absence.pdf', $params))."', '_blank')");
                }),

            Action::make('export_absence_summary')
                ->label('Exportar Resumen')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'absence')
                ->modalHeading('Exportar resumen de ausencias')
                ->modalDescription('Seleccione las columnas a incluir. Una fila por empleado con los totales del período.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(AbsenceReportSummaryExport::availableColumns())
                        ->default(AbsenceReportSummaryExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new AbsenceReportSummaryExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'ausencias_resumen_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),

            Action::make('export_absence_detail')
                ->label('Exportar Detalle')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->activeTab === 'absence')
                ->modalHeading('Exportar detalle de ausencias')
                ->modalDescription('Seleccione las columnas a incluir. Una fila por ausencia.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas a incluir')
                        ->options(AbsenceReportDetailExport::availableColumns())
                        ->default(AbsenceReportDetailExport::defaultColumns())
                        ->columns(3)
                        ->required(),
                ])
                ->action(function (array $data) {
                    [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->resolveActiveFilters();
                    Notification::make()->success()->title('Exportación iniciada')->body('El archivo se descargará en breve.')->send();

                    return Excel::download(
                        new AbsenceReportDetailExport($from, $to, $companyId, $branchId, $deptId, $employeeId, $data['columns']),
                        'ausencias_detalle_'.now()->format('Y_m_d').'.xlsx'
                    );
                }),
        ];
    }

    /**
     * Define la tabla según el tab activo, con filtros compartidos.
     */
    public function table(Table $table): Table
    {
        $query = match ($this->activeTab) {
            'absence' => $this->buildAbsenceQuery(),
            'overtime' => $this->buildOvertimeQuery(),
            default => $this->buildAttendanceQuery(),
        };

        $base = $table
            ->query($query)
            ->filters($this->sharedFilters(), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->persistFiltersInSession()
            ->defaultSort('employees.last_name', 'asc')
            ->paginated([25, 50, 100])
            ->striped();

        return match ($this->activeTab) {
            'absence' => $this->applyAbsenceTable($base),
            'overtime' => $this->applyOvertimeTable($base),
            default => $this->applyAttendanceTable($base),
        };
    }

    /**
     * Aplica columnas y estado vacío para el tab de asistencias.
     */
    private function applyAttendanceTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->getStateUsing(fn ($record) => $record->last_name.', '.$record->first_name)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('employees.last_name', $direction)->orderBy('employees.first_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn ($q) => $q->where('employees.first_name', 'like', "%{$search}%")
                            ->orWhere('employees.last_name', 'like', "%{$search}%")
                    ))
                    ->weight('medium'),

                TextColumn::make('ci')
                    ->label('CI')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn () => Branch::whereHas('company', fn ($q) => $q->active())->count() > 1),

                TextColumn::make('department_name')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('days_present')
                    ->label('Presentes')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('days_absent')
                    ->label('Ausencias')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                TextColumn::make('days_leave')
                    ->label('Licencias')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),

                TextColumn::make('total_net_hours')
                    ->label('Horas Netas')
                    ->suffix(' h')
                    ->numeric(2)
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_net_hours {$direction}")),

                TextColumn::make('total_anomalies')
                    ->label('Anomalías')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->emptyStateHeading('Sin registros de asistencia')
            ->emptyStateDescription('No hay marcaciones en el período y filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    /**
     * Aplica columnas y estado vacío para el tab de ausencias.
     */
    private function applyAbsenceTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->getStateUsing(fn ($record) => $record->last_name.', '.$record->first_name)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('employees.last_name', $direction)->orderBy('employees.first_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn ($q) => $q->where('employees.first_name', 'like', "%{$search}%")
                            ->orWhere('employees.last_name', 'like', "%{$search}%")
                    ))
                    ->weight('medium'),

                TextColumn::make('ci')
                    ->label('CI')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn () => Branch::whereHas('company', fn ($q) => $q->active())->count() > 1),

                TextColumn::make('department_name')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('total_absences')
                    ->label('Total')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_absences {$direction}")),

                TextColumn::make('total_pending')
                    ->label('Pendientes')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_pending {$direction}")),

                TextColumn::make('total_justified')
                    ->label('Justificadas')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_justified {$direction}")),

                TextColumn::make('total_unjustified')
                    ->label('Injustificadas')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_unjustified {$direction}")),

                TextColumn::make('total_deduction_amount')
                    ->label('Deducciones (Gs.)')
                    ->numeric(0, ',', '.')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_deduction_amount {$direction}")),
            ])
            ->emptyStateHeading('Sin ausencias registradas')
            ->emptyStateDescription('No hay ausencias en el período y filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-x-circle');
    }

    /**
     * Filtros compartidos entre ambos tabs: período, empresa, sucursal y departamento.
     */
    private function sharedFilters(): array
    {
        return [
            Filter::make('period')
                ->label('Período')
                ->form([
                    DatePicker::make('from_date')
                        ->label('Desde')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->default(now()->startOfMonth()),

                    DatePicker::make('to_date')
                        ->label('Hasta')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->default(now()->endOfMonth()),
                ])
                ->columns(2)
                ->query(
                    fn (Builder $query, array $data) => $query
                        ->when($data['from_date'] ?? null, fn ($q, $v) => $q->where('ad.date', '>=', $v))
                        ->when($data['to_date'] ?? null, fn ($q, $v) => $q->where('ad.date', '<=', $v))
                )
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    if ($data['from_date'] ?? null) {
                        $indicators[] = 'Desde: '.Carbon::parse($data['from_date'])->format('d/m/Y');
                    }
                    if ($data['to_date'] ?? null) {
                        $indicators[] = 'Hasta: '.Carbon::parse($data['to_date'])->format('d/m/Y');
                    }

                    return $indicators;
                }),

            ...(Company::active()->count() > 1 ? [
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(
                        fn (Builder $query, array $data) => $data['value']
                            ? $query->whereExists(fn ($sub) => $sub->selectRaw(1)
                                ->from('branches')
                                ->whereColumn('branches.id', 'employees.branch_id')
                                ->where('branches.company_id', $data['value']))
                            : $query
                    ),
            ] : []),

            SelectFilter::make('branch_id')
                ->label('Sucursal')
                ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->query(
                    fn (Builder $query, array $data) => $data['value']
                        ? $query->where('employees.branch_id', $data['value'])
                        : $query
                ),

            SelectFilter::make('department_id')
                ->label('Departamento')
                ->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->query(
                    fn (Builder $query, array $data) => $data['value']
                        ? $query->whereExists(fn ($sub) => $sub->selectRaw(1)
                            ->from('contracts')
                            ->whereColumn('contracts.employee_id', 'employees.id')
                            ->where('contracts.status', 'active')
                            ->where('contracts.department_id', $data['value']))
                        : $query
                ),

            SelectFilter::make('employee_id')
                ->label('Empleado')
                ->options(
                    fn () => Employee::orderBy('last_name')->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn ($e) => [$e->id => $e->last_name.', '.$e->first_name])
                )
                ->searchable()
                ->query(
                    fn (Builder $query, array $data) => $data['value']
                        ? $query->where('employees.id', $data['value'])
                        : $query
                ),
        ];
    }

    /**
     * Query de asistencias: agrega marcaciones por empleado desde attendance_days.
     */
    private function buildAttendanceQuery(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'present'  THEN 1 ELSE 0 END), 0) AS days_present"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'absent'   THEN 1 ELSE 0 END), 0) AS days_absent"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'on_leave' THEN 1 ELSE 0 END), 0) AS days_leave"),
                DB::raw('ROUND(COALESCE(SUM(ad.net_hours), 0), 2)             AS total_net_hours'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_diurnas), 0), 2)   AS total_extra_diurnas'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_nocturnas), 0), 2) AS total_extra_nocturnas'),
                DB::raw('COALESCE(SUM(ad.late_minutes), 0)                    AS total_late_minutes'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.anomaly_flag = 1 THEN 1 ELSE 0 END), 0) AS total_anomalies'),
            ])
            ->join('attendance_days as ad', 'ad.employee_id', '=', 'employees.id')
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id');
    }

    /**
     * Query de ausencias: agrega registros de la tabla absences por empleado.
     */
    private function buildAbsenceQuery(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw('COUNT(abs.id) AS total_absences'),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'pending'     THEN 1 ELSE 0 END), 0) AS total_pending"),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'justified'   THEN 1 ELSE 0 END), 0) AS total_justified"),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'unjustified' THEN 1 ELSE 0 END), 0) AS total_unjustified"),
                DB::raw('COALESCE(SUM(CASE WHEN abs.employee_deduction_id IS NOT NULL THEN ed.custom_amount ELSE 0 END), 0) AS total_deduction_amount'),
            ])
            ->join('absences as abs', 'abs.employee_id', '=', 'employees.id')
            ->join('attendance_days as ad', 'ad.id', '=', 'abs.attendance_day_id')
            ->leftJoin('employee_deductions as ed', 'ed.id', '=', 'abs.employee_deduction_id')
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id');
    }

    /**
     * Aplica columnas y estado vacío para el tab de horas extras y tardanzas.
     */
    private function applyOvertimeTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->getStateUsing(fn ($record) => $record->last_name.', '.$record->first_name)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('employees.last_name', $direction)->orderBy('employees.first_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn ($q) => $q->where('employees.first_name', 'like', "%{$search}%")
                            ->orWhere('employees.last_name', 'like', "%{$search}%")
                    ))
                    ->weight('medium'),

                TextColumn::make('ci')
                    ->label('CI')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn () => Branch::whereHas('company', fn ($q) => $q->active())->count() > 1),

                TextColumn::make('department_name')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('total_extra_hours')
                    ->label('HE Total')
                    ->suffix(' h')
                    ->numeric(2)
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null)
                    ->description(fn ($record) => 'D: '.number_format((float) $record->total_extra_diurnas, 2).' h  |  N: '.number_format((float) $record->total_extra_nocturnas, 2).' h')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_extra_hours {$direction}")),

                TextColumn::make('days_with_extras')
                    ->label('Días con HE')
                    ->numeric()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->description(fn ($record) => (int) $record->days_approved.' aprobados'),

                TextColumn::make('total_late_minutes')
                    ->label('Tardanza Total')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->getStateUsing(function ($record): string {
                        $min = (int) $record->total_late_minutes;
                        if ($min <= 0) {
                            return '—';
                        }
                        $h = intdiv($min, 60);
                        $m = $min % 60;

                        return $h > 0 ? "{$h}h {$m}min" : "{$m}min";
                    })
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("total_late_minutes {$direction}")),

                TextColumn::make('days_late')
                    ->label('Días Tarde')
                    ->numeric()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->description(
                        fn ($record) => (int) $record->avg_late_minutes > 0
                            ? 'Prom: '.(int) $record->avg_late_minutes.' min'
                            : null
                    ),
            ])
            ->emptyStateHeading('Sin registros de horas extras ni tardanzas')
            ->emptyStateDescription('No hay datos en el período y filtros seleccionados.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    /**
     * Query de horas extras y tardanzas: agrega métricas por empleado desde attendance_days.
     * Solo incluye empleados que tienen al menos un día con extras o tardanza en el período.
     */
    private function buildOvertimeQuery(): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.first_name',
                'employees.last_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours), 0), 2)             AS total_extra_hours'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_diurnas), 0), 2)     AS total_extra_diurnas'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_nocturnas), 0), 2)   AS total_extra_nocturnas'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.extra_hours > 0 THEN 1 ELSE 0 END), 0)        AS days_with_extras'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.overtime_approved = 1 THEN 1 ELSE 0 END), 0)  AS days_approved'),
                DB::raw('COALESCE(SUM(ad.late_minutes), 0)                                        AS total_late_minutes'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.late_minutes > 0 THEN 1 ELSE 0 END), 0)       AS days_late'),
                DB::raw('ROUND(COALESCE(AVG(CASE WHEN ad.late_minutes > 0 THEN ad.late_minutes END), 0), 0) AS avg_late_minutes'),
            ])
            ->join('attendance_days as ad', 'ad.employee_id', '=', 'employees.id')
            ->where(fn ($q) => $q->where('ad.extra_hours', '>', 0)->orWhere('ad.late_minutes', '>', 0))
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id');
    }

    /**
     * Extrae los valores activos de los filtros para pasarlos a los exports.
     *
     * @return array{string|null, string|null, int|null, int|null, int|null, int|null}
     */
    private function resolveActiveFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            $f['period']['from_date'] ?? null,
            $f['period']['to_date'] ?? null,
            isset($f['company_id']['value']) ? (int) $f['company_id']['value'] : null,
            isset($f['branch_id']['value']) ? (int) $f['branch_id']['value'] : null,
            isset($f['department_id']['value']) ? (int) $f['department_id']['value'] : null,
            isset($f['employee_id']['value']) ? (int) $f['employee_id']['value'] : null,
        ];
    }
}
