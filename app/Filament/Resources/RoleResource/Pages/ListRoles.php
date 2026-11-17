<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Actions\RoleActions;
use App\Filament\Resources\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** Listado de roles con acción para crear uno nuevo. */
class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RoleActions::createSuggested(),

            CreateAction::make()
                ->label('Nuevo Rol')
                ->icon('heroicon-o-plus'),
        ];
    }
}
