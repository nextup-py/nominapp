<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeLeaveResource\Pages;
use App\Filament\Resources\EmployeeLeaveResource\RelationManagers\AuditsRelationManager;
use App\Models\Absence;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/** Recurso Filament para gestionar permisos y licencias de empleados. */
class EmployeeLeaveResource extends Resource
{
    protected static ?string $model = EmployeeLeave::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Licencias';

    protected static ?string $modelLabel = 'licencia';

    protected static ?string $pluralModelLabel = 'licencias';

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?string $slug = 'licencias';

    protected static ?int $navigationSort = 4;

    /**
     * Define el formulario para crear y editar licencias.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información de la Licencia')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship('employee', 'first_name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name}")
                            ->searchable(['first_name', 'last_name', 'email'])
                            ->preload()
                            ->required()
                            ->columnSpan(2),

                        Select::make('status')
                            ->label('Estado')
                            ->options(EmployeeLeave::getStatusOptions())
                            ->default('pending')
                            ->native(false)
                            ->required()
                            ->disabled(fn (?EmployeeLeave $record) => $record === null)
                            ->dehydrated()
                            ->columnSpan(1),

                        Select::make('type')
                            ->label('Tipo de licencia')
                            ->options(EmployeeLeave::getTypeOptions())
                            ->native(false)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Período de la Licencia')
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Fecha de inicio')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, $set) {
                                $startDate = $get('start_date');
                                $endDate = $get('end_date');

                                if ($startDate && $endDate && $endDate < $startDate) {
                                    $set('end_date', null);
                                }
                            })
                            ->columnSpan(1),

                        DatePicker::make('end_date')
                            ->label('Fecha de fin')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->minDate(fn (Get $get) => $get('start_date'))
                            ->columnSpan(1),

                        Placeholder::make('duration')
                            ->label('Duración')
                            ->content(function (Get $get) {
                                $start = $get('start_date');
                                $end = $get('end_date');
                                $startTime = $get('start_time');
                                $endTime = $get('end_time');

                                if (! $start || ! $end) {
                                    return '-';
                                }

                                // Permiso parcial: mostrar horas
                                if (filled($startTime) && filled($endTime)) {
                                    $from = Carbon::parse($start.' '.$startTime);
                                    $to = Carbon::parse($start.' '.$endTime);
                                    if ($to->gt($from)) {
                                        $diffMinutes = (int) $from->diffInMinutes($to);
                                        $hours = intdiv($diffMinutes, 60);
                                        $minutes = $diffMinutes % 60;

                                        return $minutes > 0
                                            ? "{$hours}h {$minutes}min"
                                            : "{$hours}h";
                                    }
                                }

                                $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

                                return $days.' '.($days === 1 ? 'día' : 'días');
                            })
                            ->columnSpan(1),
                    ])
                    ->columns(3),

                Section::make('Permiso por horas (opcional)')
                    ->description('Completa este bloque solo si el permiso es parcial (no abarca el día completo).')
                    ->schema([
                        TimePicker::make('start_time')
                            ->label('Hora de inicio')
                            ->seconds(false)
                            ->native(false)
                            ->live()
                            ->columnSpan(1),

                        TimePicker::make('end_time')
                            ->label('Hora de fin')
                            ->seconds(false)
                            ->native(false)
                            ->live()
                            ->after('start_time')
                            ->required(fn (Get $get) => filled($get('start_time')))
                            ->columnSpan(1),

                        Toggle::make('generates_deduction')
                            ->label('Genera descuento en la próxima nómina')
                            ->helperText('El monto se calcula automáticamente según el salario y las horas tomadas.')
                            ->default(false)
                            ->visible(fn (Get $get) => filled($get('start_time')) && filled($get('end_time')))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (?EmployeeLeave $record) => ! $record?->isPartialDay()),

                Section::make('Detalles')
                    ->schema([
                        FileUpload::make('document_path')
                            ->label('Documento de soporte')
                            ->helperText(fn (Get $get) => $get('type') === 'medical_leave'
                                ? 'Comprobante médico requerido'
                                : 'Sube un documento relevante (opcional)')
                            ->disk('public')
                            ->directory('employee-leaves')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->maxSize(5120)
                            ->downloadable()
                            ->openable()
                            ->required(fn (Get $get) => $get('type') === 'medical_leave')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * Define la tabla con columnas, filtros y acciones de fila.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label('Empleado')
                    ->formatStateUsing(fn ($record) => $record->employee
                        ? "{$record->employee->first_name} {$record->employee->last_name}"
                        : '—'
                    )
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => EmployeeLeave::getTypeOptions()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => EmployeeLeave::getTypeColors()[$state] ?? 'gray')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('duration')
                    ->label('Duración')
                    ->getStateUsing(function (EmployeeLeave $record): string {
                        if ($record->isPartialDay()) {
                            $diffMinutes = (int) (Carbon::parse($record->start_date->format('Y-m-d').' '.$record->start_time)
                                ->diffInMinutes(Carbon::parse($record->start_date->format('Y-m-d').' '.$record->end_time)));
                            $hours = intdiv($diffMinutes, 60);
                            $minutes = $diffMinutes % 60;

                            return $minutes > 0 ? "{$hours}h {$minutes}min" : "{$hours}h";
                        }

                        $days = (int) $record->start_date->diffInDays($record->end_date) + 1;

                        return $days.' '.($days === 1 ? 'día' : 'días');
                    })
                    ->badge()
                    ->color('primary')
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state): string => EmployeeLeave::getStatusOptions()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => EmployeeLeave::getStatusColors()[$state] ?? 'gray')
                    ->icon(fn (string $state): string => EmployeeLeave::getStatusIcons()[$state] ?? 'heroicon-o-question-mark-circle')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('document_path')
                    ->label('Documento')
                    ->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Solicitado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('start_date', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de licencia')
                    ->options(EmployeeLeave::getTypeOptions())
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EmployeeLeave::getStatusOptions())
                    ->multiple(),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name}")
                    ->searchable(['first_name', 'last_name'])
                    ->preload()
                    ->multiple(),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Licencia')
                        ->modalDescription(function (EmployeeLeave $record) {
                            if ($record->isPartialDay()) {
                                $hours = $record->durationInHours();
                                $base = "Se aprobará el permiso parcial ({$record->start_time} - {$record->end_time}).";
                                if ($record->generates_deduction) {
                                    $amount = number_format($record->calculatedDeductionAmount(), 0, ',', '.');
                                    $base .= " Se generará un descuento de Gs. {$amount} en la próxima nómina.";
                                }

                                return $base;
                            }

                            $count = Absence::where('employee_id', $record->employee_id)
                                ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$record->start_date, $record->end_date]))
                                ->whereIn('status', ['pending', 'unjustified'])
                                ->count();

                            $base = 'Se aprobará la licencia del empleado.';
                            if ($count > 0) {
                                $base .= " Se justificarán automáticamente {$count} ausencia(s) registrada(s) en el período.";
                            }

                            return $base;
                        })
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->visible(fn (EmployeeLeave $record) => $record->status === 'pending' && auth()->user()->can('approve_employee_leave'))
                        ->action(function (EmployeeLeave $record) {
                            $result = $record->approve(Auth::id());

                            if ($record->isPartialDay()) {
                                $body = $record->generates_deduction
                                    ? 'El permiso fue aprobado. Se generó un descuento para la próxima nómina.'
                                    : 'El permiso fue aprobado.';
                            } else {
                                $count = $result['justified_count'];
                                $dates = $result['justified_dates'] ?? [];

                                if ($count === 0) {
                                    $body = 'La licencia fue aprobada. No había ausencias pendientes en el período.';
                                } elseif ($count <= 5) {
                                    $dateList = collect($dates)
                                        ->sortBy(fn ($d) => $d->timestamp)
                                        ->map(fn ($d) => $d->translatedFormat('d/m'))
                                        ->join(', ');
                                    $body = "Se justificaron {$count} ausencia(s): {$dateList}.";
                                } else {
                                    $body = "Se justificaron {$count} ausencias del período automáticamente.";
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Licencia aprobada')
                                ->body($body)
                                ->send();
                        }),

                    Action::make('reject')
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Licencia')
                        ->modalDescription('Se rechazará esta solicitud de licencia.')
                        ->modalSubmitActionLabel('Sí, rechazar')
                        ->visible(fn (EmployeeLeave $record) => $record->status === 'pending' && auth()->user()->can('reject_employee_leave'))
                        ->action(function (EmployeeLeave $record) {
                            $record->reject();

                            Notification::make()
                                ->warning()
                                ->title('Licencia rechazada')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->except([
                                    'created_at',
                                    'updated_at',
                                ])
                                ->withFilename('employee_leaves_export.xlsx'),
                        ])
                        ->label('Exportar a Excel')
                        ->color('info')
                        ->icon('heroicon-o-arrow-down-tray'),
                ]),
            ])
            ->emptyStateHeading('No hay licencias registradas')
            ->emptyStateDescription('Comienza registrando una nueva licencia.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    /**
     * Define el infolist para visualizar los detalles de una licencia.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Empleado')
                    ->schema([
                        TextEntry::make('employee.first_name')
                            ->label('Nombre completo')
                            ->formatStateUsing(fn ($record) => $record->employee
                                ? "{$record->employee->first_name} {$record->employee->last_name}"
                                : '—'
                            )
                            ->icon('heroicon-o-user')
                            ->columnSpan(1),

                        TextEntry::make('employee.email')
                            ->label('Email')
                            ->icon('heroicon-o-envelope')
                            ->copyable()
                            ->columnSpan(1),

                        TextEntry::make('employee.activeContract.position.department.name')
                            ->label('Departamento')
                            ->icon('heroicon-o-building-office')
                            ->badge()
                            ->color('info')
                            ->columnSpan(1),

                        TextEntry::make('employee.activeContract.position.name')
                            ->label('Puesto')
                            ->icon('heroicon-o-briefcase')
                            ->badge()
                            ->color('primary')
                            ->columnSpan(1),
                    ])
                    ->columns(4),

                InfolistSection::make('Detalles de la Licencia')
                    ->schema([
                        TextEntry::make('type')
                            ->label('Tipo de licencia')
                            ->formatStateUsing(fn (string $state): string => EmployeeLeave::getTypeOptions()[$state] ?? $state)
                            ->badge()
                            ->color(fn (string $state): string => EmployeeLeave::getTypeColors()[$state] ?? 'gray')
                            ->icon(fn (string $state): string => EmployeeLeave::getTypeIcons()[$state] ?? 'heroicon-o-document-text')
                            ->columnSpan(1),

                        TextEntry::make('status')
                            ->label('Estado')
                            ->formatStateUsing(fn (string $state): string => EmployeeLeave::getStatusOptions()[$state] ?? $state)
                            ->badge()
                            ->color(fn (string $state): string => EmployeeLeave::getStatusColors()[$state] ?? 'gray')
                            ->icon(fn (string $state): string => EmployeeLeave::getStatusIcons()[$state] ?? 'heroicon-o-question-mark-circle')
                            ->columnSpan(1),

                        TextEntry::make('document_path')
                            ->label('Documento adjunto')
                            ->formatStateUsing(fn ($state) => $state ? 'Ver documento' : 'Sin documento adjunto')
                            ->url(fn ($record) => $record->document_path
                                ? asset('storage/'.$record->document_path)
                                : null)
                            ->openUrlInNewTab()
                            ->icon(fn ($state) => $state ? 'heroicon-o-document-text' : 'heroicon-o-x-circle')
                            ->color(fn ($state) => $state ? 'success' : 'gray'),
                    ])
                    ->columns(3),

                InfolistSection::make('Período de la Licencia')
                    ->schema([
                        TextEntry::make('start_date')
                            ->label('Fecha de inicio')
                            ->date('d/m/Y')
                            ->icon('heroicon-o-calendar')
                            ->columnSpan(1),

                        TextEntry::make('end_date')
                            ->label('Fecha de fin')
                            ->date('d/m/Y')
                            ->icon('heroicon-o-calendar')
                            ->columnSpan(1),

                        TextEntry::make('duration_display')
                            ->label('Duración total')
                            ->getStateUsing(function (EmployeeLeave $record): string {
                                if ($record->isPartialDay()) {
                                    $diffMinutes = (int) Carbon::parse($record->start_date->format('Y-m-d').' '.$record->start_time)
                                        ->diffInMinutes(Carbon::parse($record->start_date->format('Y-m-d').' '.$record->end_time));
                                    $hours = intdiv($diffMinutes, 60);
                                    $minutes = $diffMinutes % 60;

                                    return $minutes > 0 ? "{$hours}h {$minutes}min" : "{$hours}h";
                                }

                                $days = (int) $record->start_date->diffInDays($record->end_date) + 1;

                                return $days.' '.($days === 1 ? 'día' : 'días');
                            })
                            ->badge()
                            ->color('primary')
                            ->icon('heroicon-o-clock')
                            ->columnSpan(1),
                    ])
                    ->columns(3),

                InfolistSection::make('Horario del permiso parcial')
                    ->schema([
                        TextEntry::make('start_time')
                            ->label('Hora de inicio')
                            ->icon('heroicon-o-clock')
                            ->columnSpan(1),

                        TextEntry::make('end_time')
                            ->label('Hora de fin')
                            ->icon('heroicon-o-clock')
                            ->columnSpan(1),

                        IconEntry::make('generates_deduction')
                            ->label('Genera descuento en nómina')
                            ->boolean()
                            ->columnSpan(1),

                        TextEntry::make('deduction_amount_display')
                            ->label('Monto del descuento')
                            ->getStateUsing(fn (EmployeeLeave $record) => $record->employee_deduction_id
                                ? 'Gs. '.number_format($record->employeeDeduction?->custom_amount ?? 0, 0, ',', '.')
                                : ($record->generates_deduction ? 'Gs. '.number_format($record->calculatedDeductionAmount(), 0, ',', '.') : '—')
                            )
                            ->badge()
                            ->color('warning')
                            ->visible(fn (EmployeeLeave $record) => $record->generates_deduction)
                            ->columnSpan(1),
                    ])
                    ->columns(4)
                    ->visible(fn (EmployeeLeave $record) => $record->isPartialDay()),
            ]);
    }

    /**
     * Registra las páginas del recurso.
     *
     * @return array<string, PageRegistration>
     */
    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeLeaves::route('/'),
            'create' => Pages\CreateEmployeeLeaves::route('/create'),
            'view' => Pages\ViewEmployeeLeaves::route('/{record}'),
            'edit' => Pages\EditEmployeeLeaves::route('/{record}/edit'),
        ];
    }
}
