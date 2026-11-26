<?php

namespace App\Filament\Resources\ShiftTemplateResource\Pages;

use App\Filament\Actions\ShiftTemplateStatusAction;
use App\Filament\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/** Página de detalle de un turno: horario, uso y historial. */
class ViewShiftTemplate extends ViewRecord
{
    protected static string $resource = ShiftTemplateResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return ShiftTemplate::query()->with('company')->findOrFail($key);
    }

    /**
     * Editar a la vista; lo poco frecuente (Duplicar) y lo destructivo (Desactivar, al final) en «Más acciones».
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ShiftTemplateResource::duplicateAction(Action::class),
                ShiftTemplateStatusAction::make(Action::class),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
            EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),
        ];
    }

    /** Define la vista de detalle del turno. */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información General')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('name')->label('Nombre')->icon('heroicon-o-table-cells'),

                            TextEntry::make('shift_type')
                                ->label('Tipo de jornada')
                                ->formatStateUsing(fn ($state) => ShiftTemplate::getShiftTypeLabels()[$state] ?? $state)
                                ->badge()
                                ->color(fn ($state) => ShiftTemplate::getShiftTypeColors()[$state] ?? 'gray'),

                            TextEntry::make('is_active')
                                ->label('Estado')
                                ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                                ->badge()
                                ->color(fn ($state) => $state ? 'success' : 'gray'),

                            ColorEntry::make('color')->label('Color en el planner'),
                        ]),

                        InfoGrid::make(4)->schema([
                            TextEntry::make('_schedule')
                                ->label('Horario')
                                ->icon('heroicon-o-clock')
                                ->getStateUsing(fn (ShiftTemplate $record): string => $record->scheduleSummary()),

                            TextEntry::make('break_minutes')
                                ->label('Descanso')
                                ->suffix(' min')
                                ->visible(fn (ShiftTemplate $record): bool => ! $record->is_day_off),

                            TextEntry::make('_crosses_midnight')
                                ->label('Turno')
                                ->badge()
                                ->color('info')
                                ->getStateUsing(fn (ShiftTemplate $record): ?string => $record->crossesMidnight() ? 'Cruza medianoche' : null)
                                ->visible(fn (ShiftTemplate $record): bool => $record->crossesMidnight()),

                            TextEntry::make('company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2')
                                ->visible(fn (): bool => ShiftTemplateResource::multipleCompanies()),

                            TextEntry::make('notes')->label('Notas')->placeholder('Sin notas'),
                        ]),
                    ]),
            ]);
    }
}
