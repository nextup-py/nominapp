<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceEventResource\Pages;
use App\Models\AttendanceEvent;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Recurso Filament para gestionar marcaciones de asistencia. */
class AttendanceEventResource extends Resource
{
    protected static ?string $model = AttendanceEvent::class;

    protected static ?string $navigationLabel = 'Marcaciones';

    protected static ?string $label = 'Marcación';

    protected static ?string $pluralLabel = 'Marcaciones';

    protected static ?string $slug = 'marcaciones';

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 3;

    /**
     * Define el formulario para crear y editar marcaciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('event_type')
                    ->label('Tipo de Evento')
                    ->options(AttendanceEvent::getEventTypeOptions())
                    ->native(false)
                    ->searchable()
                    ->required(),

                DateTimePicker::make('recorded_at')
                    ->label('Fecha y hora de marcación')
                    ->required()
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->maxDate(now())
                    ->closeOnDateSelection(),
            ]);
    }

    /**
     * Define la tabla con columnas, filtros, tabs y acciones de fila.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recorded_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->description(fn ($record) => $record->recorded_at->diffForHumans())
                    ->icon('heroicon-o-clock')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('event_type')
                    ->label('Tipo de evento')
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getEventTypeLabel($state))
                    ->badge()
                    ->color(fn ($state) => AttendanceEvent::getEventTypeColor($state))
                    ->icon(fn ($state) => AttendanceEvent::getEventTypeIcon($state))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->sortable()
                    ->searchable()
                    ->wrap()
                    ->placeholder('N/A'),

                TextColumn::make('source')
                    ->label('Canal')
                    ->formatStateUsing(fn ($state) => AttendanceEvent::getSourceLabel($state ?? 'manual'))
                    ->badge()
                    ->color(fn ($state) => AttendanceEvent::getSourceColor($state ?? 'manual'))
                    ->icon(fn ($state) => AttendanceEvent::getSourceIcon($state ?? 'manual'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->placeholder('N/A'),

                TextColumn::make('location_display')
                    ->label('Ubicación')
                    ->icon('heroicon-o-map-pin')
                    ->color('gray')
                    ->limit(50)
                    ->url(fn ($record) => $record->google_maps_url)
                    ->openUrlInNewTab()
                    ->placeholder('Sin ubicación')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->placeholder('Todos los empleados')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => $record->first_name.' '.$record->last_name.' (CI: '.$record->ci.')'
                    )
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->placeholder('Todas las sucursales')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload(false)
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('event_type')
                    ->label('Tipo de evento')
                    ->placeholder('Todos los tipos')
                    ->options(AttendanceEvent::getEventTypeOptions())
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('source')
                    ->label('Canal')
                    ->placeholder('Todos los canales')
                    ->options(AttendanceEvent::getSourceOptions())
                    ->native(false)
                    ->multiple(),

                Filter::make('recorded_at')
                    ->label('Fecha de marcación')
                    ->form([
                        DatePicker::make('recorded_from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->maxDate(now()),
                        DatePicker::make('recorded_until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->maxDate(now()),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['recorded_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('recorded_at', '>=', $date),
                            )
                            ->when(
                                $data['recorded_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('recorded_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['recorded_from'] ?? null) {
                            $indicators['recorded_from'] = 'Desde: '.Carbon::parse($data['recorded_from'])->format('d/m/Y');
                        }

                        if ($data['recorded_until'] ?? null) {
                            $indicators['recorded_until'] = 'Hasta: '.Carbon::parse($data['recorded_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),

            ])
            ->actions([
                Action::make('view_day')
                    ->label('Ver jornada')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->tooltip('Abrir la jornada de este día para ver todas sus marcaciones y corregirlas')
                    ->visible(fn (AttendanceEvent $record) => $record->attendance_day_id !== null && AttendanceDayResource::canViewAny())
                    ->url(fn (AttendanceEvent $record) => AttendanceDayResource::getUrl('view', ['record' => $record->attendance_day_id])),

                ViewAction::make()
                    ->modalHeading('Detalle de Marcación')
                    ->modalCancelActionLabel('Cerrar')
                    ->modalSubmitAction(false)
                    ->infolist([
                        Grid::make(2)->schema([
                            TextEntry::make('event_type')
                                ->label('Tipo de evento')
                                ->badge()
                                ->formatStateUsing(fn ($state) => AttendanceEvent::getEventTypeLabel($state))
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
                                ->formatStateUsing(fn ($state) => $state->format('d/m/Y H:i:s').' ('.$state->diffForHumans().')'),

                            TextEntry::make('employee_name')
                                ->label('Empleado')
                                ->formatStateUsing(fn ($state, $record) => $state.($record->employee_ci ? ' — CI: '.$record->employee_ci : '')),

                            TextEntry::make('branch_name')
                                ->label('Sucursal')
                                ->badge()
                                ->color('info'),
                        ]),

                        ViewEntry::make('location')
                            ->label('Ubicación')
                            ->view('filament.resources.attendance-event.location-map'),
                    ]),

                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->modalHeading('Editar marcación')
                        ->form([
                            Hidden::make('_date'),

                            Select::make('event_type')
                                ->label('Tipo de evento')
                                ->options(AttendanceEvent::getEventTypeOptions())
                                ->native(false)
                                ->required(),

                            Placeholder::make('fecha')
                                ->label('Fecha')
                                ->content(fn (Get $get) => $get('_date')
                                    ? Carbon::parse($get('_date'))->translatedFormat('l d/m/Y')
                                    : '—'),

                            TimePicker::make('time')
                                ->label('Hora de marcación')
                                ->seconds(false)
                                ->native(false)
                                ->required(),
                        ])
                        ->mutateRecordDataUsing(fn (array $data) => array_merge($data, [
                            // $record->attributesToArray() serializa recorded_at en UTC
                            // (Carbon::toJSON()), no en la timezone de la app — sin la
                            // conversión acá, la fecha (y hora) mostradas quedan corridas.
                            'time' => Carbon::parse($data['recorded_at'])->timezone(config('app.timezone'))->format('H:i'),
                            '_date' => Carbon::parse($data['recorded_at'])->timezone(config('app.timezone'))->format('Y-m-d'),
                        ]))
                        ->mutateFormDataUsing(fn (array $data) => [
                            'event_type' => $data['event_type'],
                            'recorded_at' => Carbon::parse($data['_date'].' '.$data['time']),
                        ])
                        ->successNotificationTitle('Marcación actualizada'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar marcación?')
                        ->modalDescription('¿Está seguro de que desea eliminar esta marcación? Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->successNotificationTitle('Marcación eliminada'),
                ]),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->emptyStateHeading('No hay marcaciones registradas')
            ->emptyStateDescription('Las marcaciones aparecerán aquí cuando los empleados registren su asistencia')
            ->emptyStateIcon('heroicon-o-hand-raised')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10);
    }

    /**
     * Registra las páginas del recurso.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAttendanceEvents::route('/'),
        ];
    }
}
