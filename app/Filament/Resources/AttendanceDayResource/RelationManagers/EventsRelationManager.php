<?php

namespace App\Filament\Resources\AttendanceDayResource\RelationManagers;

use App\Models\AttendanceEvent;
use App\Services\AttendanceEventCorrectionService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** RelationManager que muestra y gestiona las marcaciones de un día de asistencia. */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Marcaciones';

    protected static ?string $modelLabel = 'Marcación';

    protected static ?string $pluralModelLabel = 'Marcaciones';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Formulario para crear y editar marcaciones manualmente.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('event_type')
                    ->label('Tipo de Evento')
                    ->options(AttendanceEvent::getEventTypeOptions())
                    ->native(false)
                    ->required()
                    ->columnSpanFull(),

                DateTimePicker::make('recorded_at')
                    ->label('Fecha y hora')
                    ->seconds(false)
                    ->maxDate(now())
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Infolist para ver el detalle de una marcación en modal.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('employee_name')
                    ->label('Empleado')
                    ->icon('heroicon-o-user'),

                TextEntry::make('event_type')
                    ->label('Tipo de evento')
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getEventTypeLabel($state))
                    ->badge()
                    ->color(fn ($state) => AttendanceEvent::getEventTypeColor($state))
                    ->icon(fn ($state) => AttendanceEvent::getEventTypeIcon($state)),

                TextEntry::make('source')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getSourceLabel($state ?? 'manual'))
                    ->color(fn ($state) => AttendanceEvent::getSourceColor($state ?? 'manual'))
                    ->icon(fn ($state) => AttendanceEvent::getSourceIcon($state ?? 'manual')),

                TextEntry::make('recorded_at')
                    ->label('Fecha y hora')
                    ->formatStateUsing(fn (AttendanceEvent $record) => $record->recorded_at->format('d/m/Y H:i:s').' — '.$record->recorded_at->diffForHumans()
                    ),

                TextEntry::make('location_display')
                    ->label('Coordenadas')
                    ->formatStateUsing(fn (AttendanceEvent $record) => $record->getFormattedLocation())
                    ->icon('heroicon-o-map-pin')
                    ->visible(fn (AttendanceEvent $record) => $record->hasValidLocation()),

                ViewEntry::make('location_map')
                    ->label('Ubicación en mapa')
                    ->view('filament.resources.attendance-event.location-map')
                    ->visible(fn (AttendanceEvent $record) => $record->hasValidLocation())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Tabla de marcaciones del día con filtros y acciones de gestión.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event_type')
            ->columns([
                TextColumn::make('recorded_at')
                    ->label('Fecha y Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->icon('heroicon-o-clock')
                    ->sortable(),

                TextColumn::make('event_type')
                    ->label('Tipo de Evento')
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getEventTypeLabel($state))
                    ->badge()
                    ->color(fn ($state) => AttendanceEvent::getEventTypeColor($state))
                    ->icon(fn ($state) => AttendanceEvent::getEventTypeIcon($state))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Canal')
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getSourceLabel($state ?? 'manual'))
                    ->badge()
                    ->color(fn ($state) => AttendanceEvent::getSourceColor($state ?? 'manual'))
                    ->icon(fn ($state) => AttendanceEvent::getSourceIcon($state ?? 'manual'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Registrado en sistema')
                    ->dateTime('d/m/Y H:i:s')
                    ->icon('heroicon-o-calendar')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Tipo de evento')
                    ->options(AttendanceEvent::getEventTypeOptions())
                    ->native(false)
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nueva marcación')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Agregar marcación manual')
                    ->modalSubmitActionLabel('Agregar marcación')
                    ->mountUsing(function (Form $form) {
                        $form->fill([
                            'recorded_at' => $this->getOwnerRecord()->date->format('Y-m-d').' '.now()->format('H:i'),
                        ]);
                    })
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, ['source' => 'manual']))
                    ->successNotificationTitle('Marcación agregada'),
            ])
            ->actions([
                ViewAction::make()
                    ->modalHeading('Detalle de Marcación'),

                EditAction::make()
                    ->modalHeading('Editar Marcación')
                    ->modalSubmitActionLabel('Guardar cambios')
                    ->successNotificationTitle('Marcación actualizada')
                    ->using(function (Model $record, array $data, $action): Model {
                        try {
                            app(AttendanceEventCorrectionService::class)
                                ->applySingle($record, $data['event_type'], $data['recorded_at']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title('No se guardaron los cambios')->body($e->getMessage())->send();
                            $action->halt();
                        }

                        return $record->fresh();
                    })
                    ->form([
                        Select::make('event_type')
                            ->label('Tipo de Evento')
                            ->options(AttendanceEvent::getEventTypeOptions())
                            ->native(false)
                            ->required()
                            ->columnSpanFull(),

                        Hidden::make('_date'),

                        Placeholder::make('fecha_display')
                            ->label('Fecha')
                            ->content(fn (Get $get) => $get('_date')
                                ? Carbon::parse($get('_date'))->translatedFormat('l d/m/Y')
                                : '—'
                            ),

                        TimePicker::make('time')
                            ->label('Hora')
                            ->seconds(false)
                            ->native(false)
                            ->required(),
                    ])
                    ->mutateRecordDataUsing(function (array $data) {
                        // $record->attributesToArray() serializa recorded_at en UTC
                        // (Carbon::toJSON()), no en la timezone de la app — sin la
                        // conversión acá, la fecha (y hora) mostradas quedan corridas.
                        $dt = Carbon::parse($data['recorded_at'])->timezone(config('app.timezone'));

                        return array_merge($data, [
                            'time' => $dt->format('H:i'),
                            '_date' => $dt->format('Y-m-d'),
                        ]);
                    })
                    ->mutateFormDataUsing(fn (array $data) => [
                        'event_type' => $data['event_type'],
                        'recorded_at' => Carbon::parse($data['_date'].' '.$data['time'])->format('Y-m-d H:i:s'),
                        'source' => 'manual',
                    ]),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->modalHeading('Eliminar Marcación')
                    ->modalDescription('¿Está seguro de que desea eliminar esta marcación? Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->successNotificationTitle('Marcación eliminada'),
            ])
            ->defaultSort('recorded_at', 'asc')
            ->emptyStateHeading('Sin marcaciones registradas')
            ->emptyStateDescription('No hay eventos de marcación registrados para este día.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
