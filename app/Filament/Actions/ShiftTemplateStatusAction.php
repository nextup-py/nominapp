<?php

namespace App\Filament\Actions;

use App\Models\ShiftTemplate;
use Filament\Notifications\Notification;

/**
 * Acción para activar o desactivar un turno, compartida por el listado (acción de tabla) y la vista de detalle
 * (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea mientras el turno esté en un patrón
 * de rotación activo o en un turno puntual de hoy en adelante (`ShiftTemplate::deactivationBlockers()`) y exige el
 * permiso `update` (las acciones personalizadas de Filament no consultan la policy).
 */
class ShiftTemplateStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (ShiftTemplate $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (ShiftTemplate $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (ShiftTemplate $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (ShiftTemplate $record) => $record->is_active
                ? 'Los inactivos no se ofrecen al armar patrones ni en el Planificador; conservan el historial'
                : 'Vuelve a ofrecerlo en patrones de rotación y en el Planificador')
            ->authorize(fn (ShiftTemplate $record) => (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (ShiftTemplate $record) => $record->is_active ? '¿Desactivar turno?' : '¿Activar turno?')
            ->modalDescription(fn (ShiftTemplate $record) => $record->is_active
                ? 'El turno "'.$record->name.'" ya no se ofrecerá al armar patrones de rotación ni en el Planificador. El historial se conserva.'
                : 'El turno "'.$record->name.'" vuelve a ofrecerse en patrones de rotación y en el Planificador.')
            ->modalSubmitActionLabel(fn (ShiftTemplate $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (ShiftTemplate $record): void {
                if ($record->is_active && $record->deactivationBlockers() !== []) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar el turno')
                        ->body(ucfirst($record->deactivationBlockersSummary()).'.')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active ? 'Turno activado' : 'Turno desactivado')
                    ->body('El turno "'.$record->name.'" '.($record->is_active ? 'vuelve a ofrecerse' : 'ya no se ofrece').' en patrones y Planificador.')
                    ->send();
            });
    }
}
