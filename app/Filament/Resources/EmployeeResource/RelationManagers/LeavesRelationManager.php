<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Resources\EmployeeLeaveResource;
use App\Models\Absence;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/** Gestiona las licencias del empleado desde su vista de detalle. */
class LeavesRelationManager extends RelationManager
{
    protected static string $relationship = 'leaves';

    protected static ?string $title = 'Licencias';

    protected static ?string $modelLabel = 'Licencia';

    protected static ?string $pluralModelLabel = 'Licencias';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para registrar y editar licencias.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('type')
                    ->label('Tipo de licencia')
                    ->options(EmployeeLeave::getTypeOptions())
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),

                DatePicker::make('start_date')
                    ->label('Fecha de inicio')
                    ->default(now())
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, $set) {
                        $startDate = $get('start_date');
                        $endDate = $get('end_date');
                        if ($startDate && $endDate && $endDate < $startDate) {
                            $set('end_date', null);
                        }
                    })
                    ->maxDate(fn (Get $get) => $get('end_date')),

                DatePicker::make('end_date')
                    ->label('Fecha de fin')
                    ->default(now())
                    ->native(false)
                    ->required()
                    ->minDate(fn (Get $get) => $get('start_date'))
                    ->afterOrEqual('start_date'),

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

                        if (filled($startTime) && filled($endTime)) {
                            $from = Carbon::parse($start.' '.$startTime);
                            $to = Carbon::parse($start.' '.$endTime);
                            if ($to->gt($from)) {
                                $diffMinutes = (int) $from->diffInMinutes($to);
                                $hours = intdiv($diffMinutes, 60);
                                $minutes = $diffMinutes % 60;

                                return $minutes > 0 ? "{$hours}h {$minutes}min" : "{$hours}h";
                            }
                        }

                        $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

                        return $days.' '.($days === 1 ? 'día' : 'días');
                    }),

                // Partial-day section — hidden by default, collapsed unless record is partial
                TimePicker::make('start_time')
                    ->label('Hora de inicio (permiso parcial)')
                    ->seconds(false)
                    ->native(false)
                    ->live()
                    ->columnSpanFull(),

                TimePicker::make('end_time')
                    ->label('Hora de fin (permiso parcial)')
                    ->seconds(false)
                    ->native(false)
                    ->live()
                    ->after('start_time')
                    ->required(fn (Get $get) => filled($get('start_time')))
                    ->columnSpanFull(),

                Toggle::make('generates_deduction')
                    ->label('Genera descuento en la próxima nómina')
                    ->helperText('El monto se calcula automáticamente según el salario y las horas tomadas.')
                    ->default(false)
                    ->visible(fn (Get $get) => filled($get('start_time')) && filled($get('end_time')))
                    ->columnSpanFull(),

                Textarea::make('reason')
                    ->label('Descripción o motivo')
                    ->placeholder('Describe el motivo de la licencia...')
                    ->rows(3)
                    ->maxLength(500)
                    ->nullable()
                    ->columnSpanFull(),

                FileUpload::make('document_path')
                    ->label('Documento comprobante')
                    ->helperText('Sube un certificado médico, solicitud u otro documento. Formatos: PDF o imágenes. Máximo 10 MB')
                    ->disk('public')
                    ->directory('employee_leaves')
                    ->visibility('public')
                    ->nullable()
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'])
                    ->maxSize(10240)
                    ->downloadable()
                    ->previewable()
                    ->openable()
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    /**
     * Define la tabla de licencias con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo de licencia')
                    ->formatStateUsing(fn ($state) => EmployeeLeave::getTypeOptions()[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => EmployeeLeave::getTypeColors()[$state] ?? 'gray')
                    ->icon(fn ($state) => EmployeeLeave::getTypeIcons()[$state] ?? 'heroicon-o-document-text')
                    ->sortable()
                    ->searchable()
                    ->wrap(),

                TextColumn::make('period')
                    ->label('Período')
                    ->getStateUsing(
                        fn ($record) => $record->start_date->format('d/m/Y').' → '.$record->end_date->format('d/m/Y')
                    )
                    ->description(function ($record) {
                        if ($record->isPartialDay()) {
                            $diffMinutes = (int) Carbon::parse($record->start_date->format('Y-m-d').' '.$record->start_time)
                                ->diffInMinutes(Carbon::parse($record->start_date->format('Y-m-d').' '.$record->end_time));
                            $hours = intdiv($diffMinutes, 60);
                            $minutes = $diffMinutes % 60;

                            return $minutes > 0
                                ? "{$record->start_time} – {$record->end_time} ({$hours}h {$minutes}min)"
                                : "{$record->start_time} – {$record->end_time} ({$hours}h)";
                        }

                        $days = (int) $record->start_date->diffInDays($record->end_date) + 1;

                        return $days.' '.($days === 1 ? 'día' : 'días');
                    })
                    ->sortable(['start_date', 'end_date']),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => EmployeeLeave::getStatusColors()[$state] ?? 'gray')
                    ->icon(fn ($state) => EmployeeLeave::getStatusIcons()[$state] ?? 'heroicon-o-question-mark-circle')
                    ->formatStateUsing(fn ($state) => EmployeeLeave::getStatusOptions()[$state] ?? $state)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('document_path')
                    ->label('Documento')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => $state ? 'Adjunto' : 'Sin documento')
                    ->icon(fn ($state) => $state ? 'heroicon-o-paper-clip' : 'heroicon-o-minus-circle')
                    ->toggleable(),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->reason)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->since()
                    ->description(fn ($record) => $record->created_at->format('d/m/Y H:i'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de licencia')
                    ->options(EmployeeLeave::getTypeOptions())
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EmployeeLeave::getStatusOptions())
                    ->multiple(),

                Filter::make('current_year')
                    ->label('Año actual')
                    ->query(fn ($query) => $query->whereYear('start_date', now()->year))
                    ->default(),

                Filter::make('with_document')
                    ->label('Con documento')
                    ->query(fn ($query) => $query->whereNotNull('document_path')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva Licencia')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Registrar nueva licencia')
                    ->modalSubmitActionLabel('Registrar')
                    ->modalWidth('2xl'),
            ])
            ->actions([
                Action::make('approve')
                    ->authorize(fn (): bool => auth()->user()?->can('approve_employee_leave') ?? false)
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar licencia')
                    ->modalDescription(function (EmployeeLeave $record) {
                        if ($record->isPartialDay()) {
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

                        $base = 'Se aprobará la licencia del empleado.'.EmployeeLeaveResource::fullDayDeductionPreview($record);
                        if ($count > 0) {
                            $base .= " Se justificarán automáticamente {$count} ausencia(s) registrada(s) en el período.";
                        }

                        return $base;
                    })
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (EmployeeLeave $record) {
                        $result = $record->approve(Auth::id());

                        $body = EmployeeLeaveResource::approvalBody($record, $result);

                        Notification::make()
                            ->success()
                            ->title('Licencia aprobada')
                            ->body($body)
                            ->send();
                    }),

                Action::make('reject')
                    ->authorize(fn (): bool => auth()->user()?->can('reject_employee_leave') ?? false)
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar licencia')
                    ->modalDescription('Se rechazará esta solicitud de licencia.')
                    ->modalSubmitActionLabel('Sí, rechazar')
                    ->action(function (EmployeeLeave $record) {
                        $record->reject();

                        Notification::make()
                            ->warning()
                            ->title('Licencia rechazada')
                            ->send();
                    }),

                Action::make('download')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn ($record) => $record->document_path !== null)
                    ->action(function ($record) {
                        return response()->download(
                            Storage::disk('public')->path($record->document_path),
                            'licencia_'.$record->type.'_'.$record->start_date->format('Y-m-d').'.'.pathinfo($record->document_path, PATHINFO_EXTENSION)
                        );
                    }),

                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->modalHeading('Editar licencia')
                        ->modalSubmitActionLabel('Guardar cambios')
                        ->modalWidth('2xl'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar licencia?')
                        ->modalDescription('¿Estás seguro de que deseas eliminar esta licencia? Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->before(function ($record) {
                            if ($record->document_path && Storage::disk('public')->exists($record->document_path)) {
                                Storage::disk('public')->delete($record->document_path);
                            }
                        }),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Más acciones'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->authorize(fn (): bool => auth()->user()?->can('approve_employee_leave') ?? false)
                        ->label('Aprobar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar licencias')
                        ->modalDescription('Se aprobarán las licencias seleccionadas que estén en estado pendiente.')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->action(function ($records) {
                            $processed = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'pending') {
                                    $record->approve(Auth::id());
                                    $processed++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Licencias aprobadas')
                                ->body("{$processed} licencia(s) aprobada(s).")
                                ->send();
                        }),

                    BulkAction::make('reject_selected')
                        ->authorize(fn (): bool => auth()->user()?->can('reject_employee_leave') ?? false)
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar licencias')
                        ->modalDescription('Se rechazarán las licencias seleccionadas que estén en estado pendiente.')
                        ->modalSubmitActionLabel('Sí, rechazar')
                        ->action(function ($records) {
                            $processed = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'pending') {
                                    $record->reject();
                                    $processed++;
                                }
                            }

                            Notification::make()
                                ->warning()
                                ->title('Licencias rechazadas')
                                ->body("{$processed} licencia(s) rechazada(s).")
                                ->send();
                        }),

                    DeleteBulkAction::make()
                        ->modalHeading('Eliminar licencias')
                        ->modalDescription('¿Estás seguro de que deseas eliminar estas licencias? Esta acción no se puede deshacer.')
                        ->before(function ($records) {
                            foreach ($records as $record) {
                                if ($record->document_path && Storage::disk('public')->exists($record->document_path)) {
                                    Storage::disk('public')->delete($record->document_path);
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No hay licencias registradas')
            ->emptyStateDescription('Comienza registrando las licencias del empleado')
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Nueva Licencia')
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
