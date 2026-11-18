<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Actions\OrgUnitStatusAction;
use App\Filament\Resources\DepartmentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDepartment extends ViewRecord
{
    protected static string $resource = DepartmentResource::class;

    /**
     * Define las acciones disponibles en la vista de detalles del departamento.
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
