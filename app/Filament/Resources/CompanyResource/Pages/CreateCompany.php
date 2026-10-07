<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Muta los datos del formulario antes de crear el registro, capitalizando los campos "name" y "trade_name" para mejorar la presentación de los datos. Se utiliza una expresión regular para capitalizar la primera letra de cada palabra, incluso si el texto contiene caracteres multibyte (como acentos).
     *
     * @param  array  $data  Los datos del formulario a mutar.
     * @return array Los datos mutados, con los campos "name" y "trade_name" capitalizados.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        foreach (['name', 'trade_name'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = preg_replace_callback('/(?:^|\s)\S/u', fn ($m) => mb_strtoupper($m[0], 'UTF-8'), $data[$field]);
            }
        }

        return $data;
    }

    /**
     * Definir la notificación personalizada que se muestra después de crear una empresa.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Empresa creada')
            ->body('La empresa "'.$this->record->display_name.'" ha sido creada exitosamente.');
    }
}
