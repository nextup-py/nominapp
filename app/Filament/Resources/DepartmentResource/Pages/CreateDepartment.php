<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use App\Models\Company;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

    /**
     * Mutar los datos del formulario antes de crear el registro, asegurando que el nombre del departamento tenga la primera letra de cada palabra en mayúscula.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Company::active()->value('id');

        if (isset($data['name'])) {
            $data['name'] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name']);
        }

        return $data;
    }

    /**
     * Definir la notificación personalizada que se muestra después de crear un departamento.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Departamento creado')
            ->body('El departamento "'.$this->record->name.'" de la empresa "'.$this->record->company->display_name.'" ha sido creado exitosamente.')
            ->send();
    }
}
