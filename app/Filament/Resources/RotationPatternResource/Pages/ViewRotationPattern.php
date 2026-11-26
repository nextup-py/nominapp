<?php

namespace App\Filament\Resources\RotationPatternResource\Pages;

use App\Filament\Actions\RotationPatternStatusAction;
use App\Filament\Resources\RotationPatternResource;
use App\Models\RotationPattern;
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

/** Página de detalle de un patrón de rotación: ciclo día por día, estado y empleados vigentes. */
class ViewRotationPattern extends ViewRecord
{
    protected static string $resource = RotationPatternResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return RotationPattern::query()->with('company')->withCount('currentEmployees')->findOrFail($key);
    }

    /**
     * Editar a la vista y, en «Más acciones», Desactivar/Activar (rojo, al final).
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                RotationPatternResource::duplicateAction(Action::class),
                RotationPatternStatusAction::make(Action::class),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
            EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),
        ];
    }

    /** Define la vista de detalle del patrón. */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información General')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('name')->label('Nombre')->icon('heroicon-o-arrow-path'),

                            TextEntry::make('is_active')
                                ->label('Estado')
                                ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                                ->badge()
                                ->color(fn ($state) => $state ? 'success' : 'gray'),

                            TextEntry::make('_cycle')
                                ->label('Ciclo')
                                ->icon('heroicon-o-calendar-days')
                                ->getStateUsing(fn (RotationPattern $record): string => $record->cycleSummary()),

                            TextEntry::make('current_employees_count')
                                ->label('Empleados vigentes')
                                ->icon('heroicon-o-user-group')
                                ->badge()
                                ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray')
                                ->formatStateUsing(fn ($state): string => match ((int) $state) {
                                    0 => 'Sin empleados asignados',
                                    1 => '1 empleado',
                                    default => (int) $state.' empleados',
                                }),
                        ]),

                        InfoGrid::make(4)->schema([
                            TextEntry::make('company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2')
                                ->visible(fn (): bool => RotationPatternResource::multipleCompanies()),

                            TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción')->columnSpan(3),
                        ]),
                    ]),

                Section::make('Ciclo día por día')
                    ->collapsible()
                    ->schema([
                        ViewEntry::make('_cycle_table')
                            ->label('')
                            ->view('filament.rotation-patterns.cycle-table')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
