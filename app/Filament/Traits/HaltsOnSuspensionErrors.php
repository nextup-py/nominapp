<?php

namespace App\Filament\Traits;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Convierte los errores de validación de la suspensión disciplinaria (lanzados desde
 * WarningObserver/SuspensionService) en una notificación y detiene el guardado de la página.
 */
trait HaltsOnSuspensionErrors
{
    /**
     * Ejecuta el guardado y, si la suspensión no es aplicable, notifica y detiene la acción.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function haltOnSuspensionError(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title('No se puede aplicar la suspensión')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}
