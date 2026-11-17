<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use App\Models\Vacation;
use App\Services\VacationService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** Gestiona las solicitudes de vacaciones del empleado desde su vista de detalle. */
class VacationsRelationManager extends RelationManager
{
    protected static string $relationship = 'vacations';

    protected static ?string $title = 'Vacaciones';

    protected static ?string $modelLabel = 'Vacación';

    protected static ?string $pluralModelLabel = 'Vacaciones';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para solicitar y editar vacaciones.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('start_date')
                    ->label('Fecha de inicio')
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->native(false)
                    ->required()
                    ->maxDate(fn (Get $get) => $get('end_date'))
                    ->helperText('Selecciona la fecha de inicio de las vacaciones')
                    ->live(),

                DatePicker::make('end_date')
                    ->label('Fecha de fin')
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->native(false)
                    ->required()
                    ->minDate(fn (Get $get) => $get('start_date') ?: now())
                    ->helperText('Selecciona la fecha de fin de las vacaciones')
                    ->live(),

                Select::make('payment_method')
                    ->label('Forma de pago')
                    ->options(Vacation::getPaymentMethodOptions())
                    ->default('immediate')
                    ->native(false)
                    ->required(),

                Select::make('status')
                    ->label('Estado')
                    ->options(Vacation::getStatusOptions())
                    ->default('pending')
                    ->native(false)
                    ->required()
                    ->disabled()
                    ->dehydrated()
                    ->helperText('El estado solo puede cambiarse usando los botones Aprobar/Rechazar')
                    ->hiddenOn('create'),

                Textarea::make('reason')
                    ->label('Motivo o comentarios')
                    ->placeholder('Opcional: Agrega notas sobre esta solicitud de vacaciones...')
                    ->rows(3)
                    ->maxLength(500)
                    ->nullable()
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }

    /**
     * Define la tabla de vacaciones con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period')
                    ->label('Período')
                    ->state(fn (Vacation $record): string => $record->period_formatted)
                    ->description(fn (Vacation $record): string => $record->days_description)
                    ->icon('heroicon-o-calendar-days')
                    ->sortable(['start_date', 'end_date'])
                    ->searchable(['start_date', 'end_date']),

                TextColumn::make('payment_method')
                    ->label('Forma de pago')
                    ->formatStateUsing(fn (Vacation $record): string => $record->payment_method_label)
                    ->badge()
                    ->color(fn (Vacation $record): string => $record->payment_method_color)
                    ->icon(fn (Vacation $record): string => $record->payment_method_icon)
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (Vacation $record): string => $record->status_color)
                    ->icon(fn (Vacation $record): string => $record->status_icon)
                    ->formatStateUsing(fn (Vacation $record): string => $record->status_label)
                    ->sortable()
                    ->searchable(),

                TextColumn::make('created_at_description')
                    ->label('Solicitado el')
                    ->description(fn (Vacation $record): string => $record->created_at_since)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at_description')
                    ->label('Última actualización')
                    ->description(fn (Vacation $record): string => $record->updated_at_since)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payment_method')
                    ->label('Forma de pago')
                    ->options(Vacation::getPaymentMethodOptions())
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Vacation::getStatusOptions())
                    ->multiple()
                    ->native(false),

                Filter::make('upcoming')
                    ->label('Próximas vacaciones')
                    ->query(fn ($query) => $query->where('start_date', '>=', now())),

                Filter::make('past')
                    ->label('Vacaciones pasadas')
                    ->query(fn ($query) => $query->where('end_date', '<', now())),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Solicitar vacaciones')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Solicitar nuevas vacaciones')
                    ->modalSubmitActionLabel('Solicitar')
                    ->modalWidth('2xl'),
            ])
            ->actions([
                Action::make('approve')
                    ->authorize(fn (): bool => auth()->user()?->can('update_vacation') ?? false)
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Vacation $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar solicitud de vacaciones')
                    ->modalDescription(
                        fn (Vacation $record) => 'Se aprobarán '.$record->days_description.
                            ' de vacaciones del '.$record->period_formatted
                    )
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (Vacation $record) {
                        VacationService::approve($record);

                        Notification::make()
                            ->title('Vacaciones aprobadas')
                            ->body("Las vacaciones de {$record->employee->full_name} fueron aprobadas.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->authorize(fn (): bool => auth()->user()?->can('update_vacation') ?? false)
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Vacation $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar solicitud de vacaciones')
                    ->modalDescription('¿Estás seguro de que deseas rechazar esta solicitud de vacaciones?')
                    ->modalSubmitActionLabel('Sí, rechazar')
                    ->action(function (Vacation $record) {
                        VacationService::reject($record);

                        Notification::make()
                            ->title('Vacaciones rechazadas')
                            ->body("Las vacaciones de {$record->employee->full_name} fueron rechazadas.")
                            ->warning()
                            ->send();
                    }),

                Action::make('mark_paid')
                    ->authorize(fn (): bool => auth()->user()?->can('update_vacation') ?? false)
                    ->label('Marcar como pagado')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Vacation $record) => $record->isApproved()
                        && $record->payment_status === 'unpaid'
                        && $record->payment_method === 'immediate')
                    ->modalHeading('Registrar pago vacacional')
                    ->modalDescription(fn (Vacation $record) => 'Monto a registrar: Gs. '.number_format((float) $record->payment_amount, 0, ',', '.'))
                    ->modalSubmitActionLabel('Sí, registrar pago')
                    ->form([
                        DatePicker::make('paid_at')
                            ->label('Fecha de pago')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (Vacation $record, array $data) {
                        VacationService::recordPayment($record, Carbon::parse($data['paid_at']));

                        Notification::make()
                            ->success()
                            ->title('Pago registrado')
                            ->body("El pago vacacional de {$record->employee->full_name} fue registrado.")
                            ->send();
                    }),

                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->modalHeading('Editar vacaciones')
                        ->modalSubmitActionLabel('Guardar cambios')
                        ->modalWidth('2xl'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar vacaciones?')
                        ->modalDescription('¿Estás seguro de que deseas eliminar esta solicitud de vacaciones? Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->before(fn (Vacation $record) => VacationService::releaseOnDelete($record))
                        ->successNotificationTitle('Vacaciones eliminadas'),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Más acciones'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->authorize(fn (): bool => auth()->user()?->can('update_vacation') ?? false)
                        ->label('Aprobar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar vacaciones')
                        ->modalDescription('Se aprobarán las vacaciones seleccionadas que estén en estado pendiente.')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->action(function ($records) {
                            $approved = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    VacationService::approve($record);
                                    $approved++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Vacaciones aprobadas')
                                ->body("Se aprobaron {$approved} solicitudes.".($skipped > 0 ? " Se omitieron {$skipped} que no estaban pendientes." : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('reject_selected')
                        ->authorize(fn (): bool => auth()->user()?->can('update_vacation') ?? false)
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar vacaciones')
                        ->modalDescription('Se rechazarán las vacaciones seleccionadas que estén en estado pendiente.')
                        ->modalSubmitActionLabel('Sí, rechazar')
                        ->action(function ($records) {
                            $rejected = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    VacationService::reject($record);
                                    $rejected++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->warning()
                                ->title('Vacaciones rechazadas')
                                ->body("Se rechazaron {$rejected} solicitudes.".($skipped > 0 ? " Se omitieron {$skipped} que no estaban pendientes." : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->modalHeading('Eliminar vacaciones')
                        ->modalDescription('¿Estás seguro de que deseas eliminar estas solicitudes? Esta acción no se puede deshacer.')
                        ->before(fn ($records) => $records->each(fn ($record) => VacationService::releaseOnDelete($record))),
                ]),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No hay vacaciones registradas')
            ->emptyStateDescription('Comienza solicitando las vacaciones del empleado')
            ->emptyStateIcon('heroicon-o-sun');
    }
}
