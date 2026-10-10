<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceMarkFailureResource\Pages;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Resource para inspeccionar y revisar manualmente intentos fallidos de
 * marcación de asistencia. Sin creación/edición manual de registros (los
 * fallos solo se generan desde el flujo de marcación), pero sí admite
 * revisión vía `approve()`/`dismiss()` para los fallos que traen datos
 * suficientes para reconstruir la marcación — ver `getApproveAction()`.
 */
class AttendanceMarkFailureResource extends Resource
{
    protected static ?string $model = AttendanceMarkFailure::class;

    protected static ?string $navigationLabel = 'Fallos de marcación';

    protected static ?string $label = 'fallo de marcación';

    protected static ?string $pluralLabel = 'fallos de marcación';

    protected static ?string $slug = 'fallos-marcacion';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 5;

    /** Sin formulario de creación/edición manual — los fallos solo se generan desde el flujo de marcación. */
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * Tabla principal con columnas, filtros y acciones de fila.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->query(
                AttendanceMarkFailure::query()
                    ->with(['employee', 'branch'])
                    ->latest('occurred_at')
            )
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Fecha/hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->searchable(false),

                TextColumn::make('mode')
                    ->label('Modo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getModeLabel($state))
                    ->color(fn (string $state) => AttendanceMarkFailure::getModeColor($state)),

                TextColumn::make('failure_type')
                    ->label('Tipo de fallo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getFailureTypeLabel($state))
                    ->color(fn (string $state) => AttendanceMarkFailure::getFailureTypeColor($state))
                    ->searchable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->getStateUsing(fn (AttendanceMarkFailure $record) => $record->employee
                        ? "{$record->employee->first_name} {$record->employee->last_name}"
                        : '—'
                    )
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('employee', fn ($q) => $q
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                        );
                    }),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('attempted_event_type')
                    ->label('Evento intentado')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'check_in' => 'Entrada',
                        'break_start' => 'Inicio descanso',
                        'break_end' => 'Fin descanso',
                        'check_out' => 'Salida',
                        default => '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'check_in' => 'success',
                        'break_start' => 'warning',
                        'break_end' => 'warning',
                        'check_out' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('resolution_status')
                    ->label('Revisión')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getResolutionStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => AttendanceMarkFailure::getResolutionStatusColors()[$state] ?? 'gray'),

                TextColumn::make('failure_message')
                    ->label('Mensaje')
                    ->limit(60)
                    ->tooltip(fn (AttendanceMarkFailure $record) => $record->failure_message)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->default('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('mode')
                    ->label('Modo')
                    ->options([
                        'terminal' => 'Terminal',
                        'mobile' => 'Móvil',
                        'unknown' => 'Desconocido',
                    ]),

                SelectFilter::make('failure_type')
                    ->label('Tipo de fallo')
                    ->options(AttendanceMarkFailure::getFailureTypeOptions()),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name'),

                SelectFilter::make('resolution_status')
                    ->label('Revisión')
                    ->options(AttendanceMarkFailure::getResolutionStatusOptions()),

                Filter::make('occurred_at')
                    ->label('Período')
                    ->form([
                        DatePicker::make('from')
                            ->label('Desde')
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('until')
                            ->label('Hasta')
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->whereDate('occurred_at', '>=', $v))
                            ->when($data['until'], fn ($q, $v) => $q->whereDate('occurred_at', '<=', $v));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from']) {
                            $indicators[] = Indicator::make('Desde: '.\Illuminate\Support\Carbon::parse($data['from'])->format('d/m/Y'))
                                ->removeField('from');
                        }
                        if ($data['until']) {
                            $indicators[] = Indicator::make('Hasta: '.\Illuminate\Support\Carbon::parse($data['until'])->format('d/m/Y'))
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('diagnose')
                        ->label('Diagnóstico')
                        ->icon('heroicon-o-light-bulb')
                        ->color('warning')
                        ->modalHeading(fn (AttendanceMarkFailure $record) => 'Diagnóstico: '.AttendanceMarkFailure::getFailureTypeLabel($record->failure_type))
                        ->modalContent(fn (AttendanceMarkFailure $record) => view(
                            'filament.modals.attendance-mark-failure-diagnosis',
                            ['record' => $record]
                        ))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar'),

                    static::getApproveAction(),
                ]),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100]);
    }

    /**
     * Acción "Aprobar" — reconstruye el `AttendanceEvent` a partir de los
     * datos del fallo, permitiendo ajustar el tipo de evento y la hora antes
     * de confirmar (ej. si el admin determina que en realidad correspondía
     * otro tipo de marcación). Solo visible si `canBeResolved()`.
     */
    public static function getApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (AttendanceMarkFailure $record) => $record->canBeResolved())
            ->modalHeading('Aprobar marcación')
            ->modalDescription('Se creará la marcación de asistencia correspondiente. Podés ajustar el tipo de evento y la hora antes de confirmar.')
            ->modalSubmitActionLabel('Aprobar y registrar')
            ->form([
                Select::make('event_type')
                    ->label('Tipo de evento')
                    ->options([
                        'check_in' => AttendanceEvent::getEventTypeLabel('check_in'),
                        'break_start' => AttendanceEvent::getEventTypeLabel('break_start'),
                        'break_end' => AttendanceEvent::getEventTypeLabel('break_end'),
                        'check_out' => AttendanceEvent::getEventTypeLabel('check_out'),
                    ])
                    ->default(fn (AttendanceMarkFailure $record) => $record->attempted_event_type)
                    ->native(false)
                    ->required(),

                DateTimePicker::make('recorded_at')
                    ->label('Fecha y hora')
                    ->default(fn (AttendanceMarkFailure $record) => filled($record->metadata['recorded_at'] ?? null)
                        ? Carbon::parse($record->metadata['recorded_at'])
                        : $record->occurred_at)
                    ->seconds(false)
                    ->native(false)
                    ->required(),

                Textarea::make('notes')
                    ->label('Notas (opcional)')
                    ->rows(2),
            ])
            ->action(function (AttendanceMarkFailure $record, array $data) {
                $result = $record->approve(
                    Auth::id(),
                    $data['event_type'],
                    Carbon::parse($data['recorded_at']),
                    $data['notes'] ?? null,
                );

                if ($result['success']) {
                    Notification::make()->success()->title('Marcación aprobada')->body($result['message'])->send();
                } else {
                    Notification::make()->danger()->title('No se pudo aprobar')->body($result['message'])->send();
                }
            });
    }

    /**
     * Acción "Descartar" — marca el fallo como revisado sin crear ninguna
     * marcación. Transición irreversible (sin undo), por eso solo vive en
     * los header actions del ViewRecord, nunca como row action de la tabla.
     */
    public static function getDismissAction(): Action
    {
        return Action::make('dismiss')
            ->label('Descartar')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (AttendanceMarkFailure $record) => $record->isPending())
            ->requiresConfirmation()
            ->modalHeading('Descartar fallo')
            ->modalDescription('Se marcará este fallo como revisado sin crear ninguna marcación de asistencia. Usalo cuando el conflicto ya no aplica — por ejemplo, si la marcación se cargó manualmente desde otro lado.')
            ->modalSubmitActionLabel('Sí, descartar')
            ->form([
                Textarea::make('notes')
                    ->label('Notas (opcional)')
                    ->rows(2),
            ])
            ->action(function (AttendanceMarkFailure $record, array $data) {
                $result = $record->dismiss(Auth::id(), $data['notes'] ?? null);

                if ($result['success']) {
                    Notification::make()->success()->title('Fallo descartado')->send();
                } else {
                    Notification::make()->danger()->title('No se pudo descartar')->body($result['message'])->send();
                }
            });
    }

    /**
     * Infolist con todos los detalles del intento fallido.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Resumen')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('occurred_at')
                            ->label('Fecha y hora')
                            ->dateTime('d/m/Y H:i:s'),

                        TextEntry::make('mode')
                            ->label('Modo')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getModeLabel($state))
                            ->color(fn (string $state) => AttendanceMarkFailure::getModeColor($state)),

                        TextEntry::make('failure_type')
                            ->label('Tipo de fallo')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getFailureTypeLabel($state))
                            ->color(fn (string $state) => AttendanceMarkFailure::getFailureTypeColor($state)),

                        TextEntry::make('attempted_event_type')
                            ->label('Evento intentado')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => match ($state) {
                                'check_in' => 'Entrada',
                                'break_start' => 'Inicio descanso',
                                'break_end' => 'Fin descanso',
                                'check_out' => 'Salida',
                                default => '—',
                            })
                            ->color(fn (?string $state) => match ($state) {
                                'check_in' => 'success',
                                'break_start', 'break_end' => 'warning',
                                'check_out' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('failure_message')
                            ->label('Mensaje de error')
                            ->columnSpan(2),
                    ]),

                InfoSection::make('Empleado y Sucursal')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('employee.first_name')
                            ->label('Empleado')
                            ->getStateUsing(fn (AttendanceMarkFailure $record) => $record->employee
                                ? "{$record->employee->first_name} {$record->employee->last_name} (CI: {$record->employee->ci})"
                                : '— (no identificado)'
                            ),

                        TextEntry::make('branch.name')
                            ->label('Sucursal')
                            ->default('—'),
                    ]),

                InfoSection::make('Revisión')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->schema([
                        InfoGrid::make(3)->schema([
                            TextEntry::make('resolution_status')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => AttendanceMarkFailure::getResolutionStatusLabels()[$state] ?? $state)
                                ->color(fn (string $state) => AttendanceMarkFailure::getResolutionStatusColors()[$state] ?? 'gray'),

                            TextEntry::make('resolvedBy.name')
                                ->label('Revisado por')
                                ->placeholder('Sin revisar'),

                            TextEntry::make('resolved_at')
                                ->label('Revisado el')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),
                        ]),

                        TextEntry::make('resolvedEvent.id')
                            ->label('Marcación creada')
                            ->placeholder('Ninguna')
                            ->getStateUsing(fn (AttendanceMarkFailure $record) => $record->resolvedEvent
                                ? AttendanceEvent::getEventTypeLabel($record->resolvedEvent->event_type).' — '.$record->resolvedEvent->recorded_at->format('d/m/Y H:i')
                                : null
                            )
                            ->visible(fn (AttendanceMarkFailure $record) => $record->isApproved()),

                        TextEntry::make('resolution_notes')
                            ->label('Notas de revisión')
                            ->placeholder('Sin notas')
                            ->columnSpanFull()
                            ->visible(fn (AttendanceMarkFailure $record) => ! $record->isPending()),
                    ]),

                InfoSection::make('Jornada abierta anterior')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->description('El empleado tiene una entrada sin salida. Corregirla desde Asistencias (agregar la salida que falta) antes de resolver este conflicto.')
                    ->schema([
                        TextEntry::make('metadata.open_day_date')
                            ->label('Jornada sin salida del')
                            ->formatStateUsing(fn (?string $state) => $state ? Carbon::parse($state)->format('d/m/Y') : null)
                            ->url(fn (AttendanceMarkFailure $record) => AttendanceDayResource::getUrl('view', ['record' => $record->metadata['open_day_id']]))
                            ->color('primary'),
                    ])
                    ->visible(fn (AttendanceMarkFailure $record) => filled($record->metadata['open_day_id'] ?? null)),

                InfoSection::make('Red y Ubicación')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('ip_address')
                            ->label('Dirección IP')
                            ->default('—'),

                        TextEntry::make('location')
                            ->label('Coordenadas GPS')
                            ->getStateUsing(fn (AttendanceMarkFailure $record) => isset($record->location['lat'], $record->location['lng'])
                                ? "{$record->location['lat']}, {$record->location['lng']}"
                                : '—'
                            ),
                    ]),

                InfoSection::make('Metadatos adicionales')
                    ->icon('heroicon-o-code-bracket')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('metadata')
                            ->label('')
                            ->columnSpanFull()
                            ->getStateUsing(fn (AttendanceMarkFailure $record) => collect($record->metadata ?? [])
                                ->map(fn ($value) => is_array($value)
                                    ? json_encode($value, JSON_UNESCAPED_UNICODE)
                                    : $value)
                                ->toArray()
                            ),
                    ])
                    ->visible(fn (AttendanceMarkFailure $record) => ! empty($record->metadata)),
            ]);
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceMarkFailures::route('/'),
            'view' => Pages\ViewAttendanceMarkFailure::route('/{record}'),
        ];
    }
}
