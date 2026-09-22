<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/** Edita un rol, precargando y resincronizando sus permisos por módulo. */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Eliminar')
                ->modalHeading('¿Eliminar rol?')
                ->modalSubmitActionLabel('Sí, eliminar'),
        ];
    }

    /**
     * Distribuye los permisos actuales del rol en los campos virtuales `group_*`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $currentPermissionNames = $this->record->permissions->pluck('name')->all();

        foreach (PermissionSeeder::GROUPS as $groupName => $models) {
            $groupPermissionNames = [];
            foreach ($models as $model) {
                foreach (PermissionSeeder::ABILITIES as $ability) {
                    $groupPermissionNames[] = "{$ability}_{$model}";
                }
                foreach (array_keys(BusinessActionPermissionSeeder::ACTIONS[$model] ?? []) as $action) {
                    $groupPermissionNames[] = "{$action}_{$model}";
                }
            }

            $data[RoleResource::groupFieldKey($groupName)] = array_values(
                array_intersect($currentPermissionNames, $groupPermissionNames)
            );
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions(RoleResource::collectPermissions($this->data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
