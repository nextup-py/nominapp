<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AbsenceResource\Pages;
use App\Filament\Resources\AbsenceResource\RelationManagers\AuditsRelationManager;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class AbsenceResource extends Resource
{
    protected static ?string $model = Absence::class;

    protected static ?string $navigationLabel = 'Ausencias';

    protected static ?string $label = 'ausencia';

    protected static ?string $pluralLabel = 'ausencias';

    protected static ?string $slug = 'ausencias';

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Empleado')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('attendance_day_id', null))
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        Select::make('attendance_day_id')
                            ->label('Día de Asistencia')
                            ->options(function (Get $get) {
                                $employeeId = $get('employee_id');

                                if (! $employeeId) {
                                    return [];
                                }

                                return AttendanceDay::query()
                                    ->where('employee_id', $employeeId)
                                    ->where('status', 'absent')
                                    ->whereDoesntHave('absence') // Solo mostrar días sin registro de ausencia
                                    ->orderBy('date', 'desc')
                                    ->limit(50) // Limitar a los últimos 50 días
                                    ->get()
                                    ->pluck('date_formatted', 'id');
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => AttendanceDay::find($value)?->date_formatted)
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->disabled(fn (string $operation, Get $get) => $operation === 'edit' || ! $get('employee_id'))
                            ->helperText(fn (Get $get) => ! $get('employee_id')
                                ? 'Primero selecciona un empleado'
                                : 'Solo se muestran días con ausencia que no tienen registro'),
                    ])
                    ->columns(2),

                Section::make('Estado de la Ausencia')
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->options(Absence::getStatusOptions())
                            ->required()
                            ->native(false)
                            ->default('pending')
                            ->live()
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                if ($state === 'justified') {
                                    $set('reviewed_at', now());
                                    $set('reviewed_by_id', Auth::id());
                                } elseif ($state === 'unjustified') {
                                    $set('reviewed_at', now());
                                    $set('reviewed_by_id', Auth::id());
                                }
                            }),

                        Textarea::make('reason')
                            ->label('Motivo de la Ausencia')
                            ->placeholder('Ingrese el motivo de la ausencia...')
                            ->rows(1)
                            ->nullable(),

                        FileUpload::make('documents')
                            ->label('Documentos Justificativos')
                            ->multiple()
                            ->directory('absences-documents')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120) // 5MB
                            ->columnSpanFull()
                            ->helperText('Sube documentos que justifiquen la ausencia (máx. 5MB por archivo).'),
                    ])
                    ->columns(2),

                Section::make('Revisión')
                    ->schema([
                        DateTimePicker::make('reported_at')
                            ->label('Fecha de Reporte')
                            ->default(now())
                            ->required()
                            ->native(false)
                            ->disabled(),

                        Select::make('reported_by_id')
                            ->label('Reportado por')
                            ->relationship('reportedBy', 'name')
                            ->default(Auth::id())
                            ->native(false)
                            ->disabled(),

                        DateTimePicker::make('reviewed_at')
                            ->label('Fecha de Revisión')
                            ->native(false)
                            ->disabled(),

                        Select::make('reviewed_by_id')
                            ->label('Revisado por')
                            ->relationship('reviewedBy', 'name')
                            ->native(false)
                            ->disabled(),

                        Textarea::make('review_notes')
                            ->label('Notas de Revisión')
                            ->rows(3)
                            ->columnSpanFull(),

                        Select::make('employee_deduction_id')
                            ->label('Deducción Generada')
                            ->relationship('employeeDeduction', 'id')
                            ->native(false)
                            ->disabled()
                            ->visible(fn ($record) => $record?->employee_deduction_id !== null),
                    ])
                    ->columns(2)
                    ->visible(fn (string $operation) => $operation === 'edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attendanceDay.date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->icon('heroicon-o-identification')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('Cédula copiada'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Absence::getStatusLabel($state))
                    ->color(fn (string $state): string => Absence::getStatusColor($state))
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(45)
                    ->tooltip(fn ($record) => $record->reason)
                    ->placeholder('Sin motivo')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('reported_at')
                    ->label('Reportado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reportedBy.name')
                    ->label('Reportado por')
                    ->default('Sistema')
                    ->badge()
                    ->color(fn ($record) => $record->reported_by_id ? 'primary' : 'gray')
                    ->icon(fn ($record) => $record->reported_by_id ? 'heroicon-o-user' : 'heroicon-o-cog-6-tooth')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reviewed_at')
                    ->label('Revisado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reviewedBy.name')
                    ->label('Revisado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('reported_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Absence::getStatusOptions())
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false)
                    ->multiple(),

                Filter::make('absence_date')
                    ->label('Fecha de Ausencia')
                    ->form([
                        DatePicker::make('absence_from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        DatePicker::make('absence_until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['absence_from'],
                                fn (Builder $query, $date): Builder => $query->whereHas(
                                    'attendanceDay',
                                    fn (Builder $q) => $q->whereDate('date', '>=', $date)
                                ),
                            )
                            ->when(
                                $data['absence_until'],
                                fn (Builder $query, $date): Builder => $query->whereHas(
                                    'attendanceDay',
                                    fn (Builder $q) => $q->whereDate('date', '<=', $date)
                                ),
                            );
                    }),
            ])
            ->actions([])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_justify')
                        ->label('Justificar seleccionadas')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('justify_absence'))
                        ->tooltip('Justifica todas las ausencias seleccionadas')
                        ->requiresConfirmation()
                        ->modalHeading('Justificar Ausencias Seleccionadas')
                        ->modalDescription('Se marcarán como justificadas todas las ausencias seleccionadas que no lo sean ya. Las deducciones existentes serán eliminadas.')
                        ->form([
                            Textarea::make('review_notes')
                                ->label('Notas de revisión')
                                ->placeholder('Motivo de la justificación...')
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = 0;
                            foreach ($records as $record) {
                                if (! $record->isJustified()) {
                                    $record->justify(Auth::id(), $data['review_notes'] ?? null);
                                    $count++;
                                }
                            }
                            Notification::make()
                                ->success()
                                ->title("{$count} ausencia(s) justificada(s)")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_unjustify')
                        ->label('Marcar injustificadas')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn () => auth()->user()->can('mark_unjustified_absence'))
                        ->tooltip('Marca como injustificadas y genera deducciones para las seleccionadas')
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Injustificadas')
                        ->modalDescription('Se generará una deducción salarial para cada ausencia seleccionada que no esté ya marcada como injustificada.')
                        ->form([
                            Textarea::make('review_notes')
                                ->label('Notas de revisión')
                                ->placeholder('Motivo por el cual se marcan como injustificadas...')
                                ->rows(3)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = 0;
                            foreach ($records as $record) {
                                if (! $record->isUnjustified()) {
                                    $record->markAsUnjustified(Auth::id(), $data['review_notes']);
                                    $count++;
                                }
                            }
                            Notification::make()
                                ->success()
                                ->title("{$count} ausencia(s) marcada(s) como injustificadas")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Información del Empleado')
                    ->schema([
                        TextEntry::make('employee.full_name')
                            ->label('Empleado'),
                        TextEntry::make('employee.ci')
                            ->label('CI')
                            ->badge()
                            ->color('gray')
                            ->copyable()
                            ->copyMessage('CI copiada'),
                        TextEntry::make('attendanceDay.date')
                            ->label('Fecha de Ausencia')
                            ->date('d/m/Y'),
                        TextEntry::make('reason')
                            ->label('Motivo')
                            ->placeholder('Sin motivo registrado')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                InfoSection::make('Estado y Revisión')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => Absence::getStatusLabel($state))
                            ->color(fn (string $state): string => Absence::getStatusColor($state)),
                        TextEntry::make('reported_at')
                            ->label('Fecha de Reporte')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('reportedBy.name')
                            ->label('Reportado por')
                            ->default('Sistema (automático)')
                            ->badge()
                            ->color(fn ($record) => $record->reported_by_id ? 'primary' : 'gray'),
                        TextEntry::make('reviewed_at')
                            ->label('Fecha de Revisión')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Pendiente'),
                        TextEntry::make('reviewedBy.name')
                            ->label('Revisado por')
                            ->placeholder('Pendiente'),
                        TextEntry::make('review_notes')
                            ->label('Notas de Revisión')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                InfoSection::make('Deducción')
                    ->schema([
                        TextEntry::make('employeeDeduction.id')
                            ->label('ID Deducción')
                            ->badge()
                            ->color('danger')
                            ->placeholder('Sin deducción generada'),
                    ])
                    ->visible(fn ($record) => $record?->employee_deduction_id !== null),

                InfoSection::make('Licencia Vinculada')
                    ->schema([
                        TextEntry::make('employeeLeave.type')
                            ->label('Tipo')
                            ->formatStateUsing(fn ($state) => $state ? (EmployeeLeave::getTypeOptions()[$state] ?? $state) : '—')
                            ->badge()
                            ->color(fn ($record) => $record?->employeeLeave
                                ? (EmployeeLeave::getTypeColors()[$record->employeeLeave->type] ?? 'gray')
                                : 'gray'),

                        TextEntry::make('employeeLeave.start_date')
                            ->label('Período')
                            ->getStateUsing(fn ($record) => $record?->employeeLeave
                                ? $record->employeeLeave->start_date->format('d/m/Y').' al '.$record->employeeLeave->end_date->format('d/m/Y')
                                : '—'),

                        TextEntry::make('employeeLeave.status')
                            ->label('Estado')
                            ->formatStateUsing(fn ($state) => $state ? (EmployeeLeave::getStatusOptions()[$state] ?? $state) : '—')
                            ->badge()
                            ->color(fn ($state) => $state ? (EmployeeLeave::getStatusColors()[$state] ?? 'gray') : 'gray'),
                    ])
                    ->columns(3)
                    ->visible(fn ($record) => $record?->employee_leave_id !== null),
            ]);
    }

    public static function getExcelExportAction(): ExportAction
    {
        return ExportAction::make('export_excel')
            ->label('Exportar a Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->visible(fn () => auth()->user()->can('export_absence'))
            ->tooltip('Exportar registros visibles respetando filtros y tabs activos')
            ->exports([
                ExcelExport::make()
                    ->fromTable()
                    ->except(['id', 'employee_id', 'attendance_day_id', 'employee_deduction_id', 'created_at', 'updated_at', 'documents'])
                    ->withFilename(fn () => 'ausencias_'.now()->format('d_m_Y_H_i_s')),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas del recurso de ausencias, incluyendo las rutas para listar, crear, editar y ver registros de ausencias.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAbsences::route('/'),
            'create' => Pages\CreateAbsence::route('/create'),
            'view' => Pages\ViewAbsence::route('/{record}'),
            'edit' => Pages\EditAbsence::route('/{record}/edit'),
        ];
    }

    /**
     * Define la insignia de navegación para el recurso de ausencias, mostrando el número de ausencias pendientes de revisión del día hoy, o null si no hay ninguna.
     */
    public static function getNavigationBadge(): ?string
    {
        $today = Carbon::today();

        $pendingCount = Absence::query()
            ->where('status', 'pending')
            ->whereBetween('created_at', [$today, $today->copy()->endOfDay()])
            ->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    /**
     * Define el color de la insignia de navegación para el recurso de ausencias.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Define el tooltip de la insignia de navegación para el recurso de ausencias, indicando que el número representa las "Ausencias pendientes de revisión".
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Ausencias pendientes de revisión';
    }
}
