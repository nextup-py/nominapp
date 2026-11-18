<?php

namespace App\Filament\Resources\TerminalResource\Pages;

use App\Filament\Resources\TerminalResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/** Creación de una nueva terminal de marcación. */
class CreateTerminal extends CreateRecord
{
    protected static string $resource = TerminalResource::class;

    /**
     * Notificación de éxito al crear la terminal.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Terminal creado')
            ->body("El terminal \"{$this->record->name}\" fue creado. Generar el enlace de configuración para vincular el dispositivo.");
    }

    /**
     * Redirige al detalle del terminal recién creado con `?provision=1` —
     * ViewTerminal::mount() lo detecta y abre de una el modal "Generar enlace
     * de configuración", para que el admin no tenga que volver a la lista a
     * buscar el QR justo después de crear el terminal.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]).'?provision=1';
    }
}
