<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Actions\OrgUnitStatusAction;
use App\Filament\Resources\PositionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPosition extends ViewRecord
{
    protected static string $resource = PositionResource::class;

    /**
     * Define las acciones disponibles en la vista de detalles del cargo.
     */
    protected function getHeaderActions(): array
    {
        return [
            OrgUnitStatusAction::make(Action::class),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
