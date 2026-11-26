<?php

namespace App\Filament\Actions;

use App\Models\RotationPattern;
use Filament\Notifications\Notification;

/**
 * Acción para activar o desactivar un patrón de rotación, compartida por el listado (acción de tabla) y la vista de detalle
 * (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea mientras tenga asignaciones vigentes o
 * futuras (`RotationPattern::deactivationBlockers()`) y exige el
 * permiso `update` (las acciones personalizadas de Filament no consultan la policy).
 */
class RotationPatternStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (RotationPattern $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (RotationPattern $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (RotationPattern $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (RotationPattern $record) => $record->is_active
                ? 'Los inactivos no se ofrecen al asignar y conservan el historial. No se pueden desactivar con empleados asignados'
                : 'Vuelve a ofrecerlo al asignar patrones')
            ->authorize(fn (RotationPattern $record) => (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (RotationPattern $record) => $record->is_active ? '¿Desactivar patrón?' : '¿Activar patrón?')
            ->modalDescription(fn (RotationPattern $record) => $record->is_active
                ? 'El patrón "'.$record->name.'" ya no se ofrecerá al asignar. El historial de asignaciones se conserva.'
                : 'El patrón "'.$record->name.'" vuelve a ofrecerse al asignar.')
            ->modalSubmitActionLabel(fn (RotationPattern $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (RotationPattern $record): void {
                if ($record->is_active && $record->deactivationBlockers() !== []) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar el patrón')
                        ->body(ucfirst($record->deactivationBlockersSummary()).'.')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active ? 'Patrón activado' : 'Patrón desactivado')
                    ->body('El patrón "'.$record->name.'" '.($record->is_active ? 'vuelve a ofrecerse' : 'ya no se ofrece').' al asignar.')
                    ->send();
            });
    }
}
