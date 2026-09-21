<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

/** Crea un rol y sincroniza los permisos seleccionados por módulo. */
class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Inyecta el guard de autenticación al crear el rol.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions(RoleResource::collectPermissions($this->data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
