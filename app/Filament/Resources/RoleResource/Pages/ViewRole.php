<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Actions\RoleActions;
use App\Filament\Resources\RoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Detalle de un rol: descripción, usuarios y permisos por módulo, con comparación contra otro rol. */
class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RoleActions::compare(),
            EditAction::make()->icon('heroicon-o-pencil-square'),
        ];
    }
}
