<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AttendanceDayResource;
use App\Models\AttendanceEvent;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/** Widget de tabla: últimas marcaciones del día actual. */
class LatestAttendances extends BaseWidget
{
    /** Solo lo ven quienes tienen permiso sobre el módulo que resume. */
    public static function canView(): bool
    {
        return AttendanceDayResource::canViewAny();
    }

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Últimas Marcaciones de Hoy';

    protected static ?string $description = 'Marcaciones del día actual en tiempo real';

    protected static ?int $sort = 5;

    /** Refresca la tabla cada 30 segundos para reflejar marcaciones nuevas. */
    protected static ?string $pollingInterval = '30s';

    /**
     * Configura la tabla con columnas, filtros y acciones.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                AttendanceEvent::query()
                    ->whereDate('recorded_at', today())
                    ->whereNotNull('employee_id')
                    ->latest('recorded_at')
            )
            ->columns([
                TextColumn::make('recorded_at')
                    ->label('Fecha y Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->description(fn ($record) => $record->recorded_at->diffForHumans())
                    ->icon('heroicon-o-clock')
                    ->sortable()
                    ->searchable(),

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
                    ->toggleable(),

                TextColumn::make('employee_name')
                    ->label('Empleado')
                    ->description(fn ($record) => $record->employee_ci ? 'CI: '.$record->employee_ci : '')
                    ->sortable()
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->placeholder('N/A'),

                TextColumn::make('branch_name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->placeholder('N/A'),
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
                    ->label('Tipo de Evento')
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
            ])
            ->actions([
                ViewAction::make()
                    ->label('Ver')
                    ->modalHeading('Detalle de Marcación')
                    ->modalCancelActionLabel('Cerrar')
                    ->modalSubmitAction(false)
                    ->infolist([
                        Grid::make(2)->schema([
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
                                ->formatStateUsing(fn ($state) => $state->format('d/m/Y H:i:s').' — '.$state->diffForHumans()),

                            TextEntry::make('branch_name')
                                ->label('Sucursal')
                                ->badge()
                                ->color('info'),
                        ]),

                        ViewEntry::make('location_map')
                            ->label('Ubicación en mapa')
                            ->view('filament.resources.attendance-event.location-map')
                            ->visible(fn (AttendanceEvent $record) => $record->hasValidLocation())
                            ->columnSpanFull(),
                    ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->except(['created_at', 'updated_at'])
                                ->withFilename('marcaciones_hoy_'.now()->format('d_m_Y_H_i_s')),
                        ])
                        ->label('Exportar seleccionados')
                        ->color('success')
                        ->icon('heroicon-o-arrow-down-tray'),
                ]),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay marcaciones de hoy')
            ->emptyStateDescription('Las marcaciones del día aparecerán aquí automáticamente.')
            ->emptyStateIcon('heroicon-o-finger-print');
    }
}
