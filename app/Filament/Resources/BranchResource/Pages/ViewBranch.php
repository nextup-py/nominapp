<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Actions\BranchStatusAction;
use App\Filament\Resources\BranchResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Página de visualización de detalle de una sucursal. */
class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * Retorna las acciones del encabezado: acceso a edición.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            BranchStatusAction::make(Action::class),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
