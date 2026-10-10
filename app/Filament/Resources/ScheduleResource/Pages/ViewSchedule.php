<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

/** Página de detalle de un horario, con sus días activos, descansos y empleados asignados. */
class ViewSchedule extends ViewRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Retorna las acciones del encabezado de la vista.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ScheduleResource::duplicateAction(Action::class),
            EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),
        ];
    }

    /**
     * Define la vista de detalle del horario con sus días activos y descansos.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información General')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre')
                            ->icon('heroicon-o-clock'),

                        TextEntry::make('shift_type')
                            ->label('Tipo de Jornada')
                            ->formatStateUsing(fn ($state) => Schedule::getShiftTypeLabels()[$state] ?? $state)
                            ->badge()
                            ->color(fn ($state) => Schedule::getShiftTypeColors()[$state] ?? 'gray'),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->placeholder('Sin descripción'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('Configuración de Días')
                    ->schema([
                        RepeatableEntry::make('activeDays')
                            ->label('')
                            ->schema([
                                Group::make([
                                    TextEntry::make('day_of_week')
                                        ->label('Día')
                                        ->formatStateUsing(fn ($state) => ScheduleDay::getDayOptions()[(int) $state] ?? $state)
                                        ->badge()
                                        ->color('info'),

                                    TextEntry::make('start_time')
                                        ->label('Entrada')
                                        ->time('H:i')
                                        ->icon('heroicon-o-arrow-right-on-rectangle')
                                        ->iconColor('success'),

                                    TextEntry::make('end_time')
                                        ->label('Salida')
                                        ->time('H:i')
                                        ->icon('heroicon-o-arrow-left-on-rectangle')
                                        ->iconColor('danger'),
                                ])->columns(3),

                                RepeatableEntry::make('breaks')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('name')
                                            ->label('Descanso')
                                            ->badge()
                                            ->color('warning'),

                                        TextEntry::make('start_time')
                                            ->label('Inicio')
                                            ->time('H:i')
                                            ->icon('heroicon-o-arrow-right-on-rectangle')
                                            ->iconColor('warning'),

                                        TextEntry::make('end_time')
                                            ->label('Fin')
                                            ->time('H:i')
                                            ->icon('heroicon-o-arrow-left-on-rectangle')
                                            ->iconColor('warning'),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }
}
