<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Resources\ScheduleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSchedule extends CreateRecord
{
    protected static string $resource = ScheduleResource::class;

    /**
     * Pone en mayúscula la primera letra del nombre del horario antes de crear el registro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = ScheduleResource::capitalizeName($data['name']);
        }

        return $data;
    }

    /**
     * Redirige a la página de detalle del horario recién creado.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Envía una notificación de éxito después de crear un nuevo horario.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Horario creado')
            ->body("El horario \"{$this->record->name}\" ha sido creado correctamente.");
    }
}
