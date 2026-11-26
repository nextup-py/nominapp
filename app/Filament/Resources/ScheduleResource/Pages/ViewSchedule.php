<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Actions\ScheduleStatusAction;
use App\Filament\Resources\ScheduleResource;
use App\Models\Schedule;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/** Página de detalle de un horario, con sus días activos, descansos y empleados asignados. */
class ViewSchedule extends ViewRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Carga los días con sus descansos y el conteo de empleados vigentes de una vez: el resumen y la tabla de días
     * los reutilizan sin consultar por día.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return Schedule::query()
            ->with('days.breaks')
            ->withCount('currentEmployees')
            ->findOrFail($key);
    }

    /**
     * Retorna las acciones del encabezado de la vista: Editar a la vista y, en «Más acciones», lo poco frecuente
     * (Duplicar) y lo destructivo (Desactivar, en rojo y al final), como en la ficha del empleado.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ScheduleResource::duplicateAction(Action::class),
                ScheduleStatusAction::make(Action::class),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
            EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),
        ];
    }

    /**
     * Define la vista de detalle del horario: datos generales con un resumen semanal y la tabla de días.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información General')
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('name')
                                ->label('Nombre')
                                ->icon('heroicon-o-clock'),

                            TextEntry::make('shift_type')
                                ->label('Tipo de Jornada')
                                ->formatStateUsing(fn ($state) => Schedule::getShiftTypeLabels()[$state] ?? $state)
                                ->badge()
                                ->color(fn ($state) => Schedule::getShiftTypeColors()[$state] ?? 'gray'),

                            TextEntry::make('is_active')
                                ->label('Estado')
                                ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                                ->badge()
                                ->color(fn ($state) => $state ? 'success' : 'gray'),

                            TextEntry::make('description')
                                ->label('Descripción')
                                ->placeholder('Sin descripción'),
                        ]),

                        InfoGrid::make(4)->schema([
                            TextEntry::make('_weekly_summary')
                                ->label('Jornada semanal')
                                ->icon('heroicon-o-calendar-days')
                                ->getStateUsing(fn (Schedule $record): string => $record->weeklySummaryLabel()),

                            TextEntry::make('current_employees_count')
                                ->label('Empleados asignados')
                                ->icon('heroicon-o-user-group')
                                ->badge()
                                ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray')
                                ->formatStateUsing(fn ($state): string => match ((int) $state) {
                                    0 => 'Sin empleados asignados',
                                    1 => '1 empleado',
                                    default => (int) $state.' empleados',
                                }),

                            TextEntry::make('_crosses_midnight')
                                ->label('Turno')
                                ->badge()
                                ->color('info')
                                ->getStateUsing(fn (Schedule $record): ?string => $record->crossesMidnight() ? 'Cruza medianoche' : null)
                                ->visible(fn (Schedule $record): bool => $record->crossesMidnight()),
                        ]),
                    ])
                    ->collapsible(),

                Section::make('Configuración de Días')
                    ->schema([
                        ViewEntry::make('_days_table')
                            ->label('')
                            ->view('filament.schedules.days-table')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }
}
