<?php

namespace App\Filament\Actions;

use App\Models\Schedule;
use Filament\Notifications\Notification;

/**
 * Acción para activar o desactivar un horario, compartida por el listado (acción de tabla) y la vista de detalle
 * (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea mientras haya asignaciones vigentes
 * o futuras (`Schedule::deactivationBlockers()`) y exige el permiso `update` (las acciones personalizadas de Filament
 * no consultan la policy). El historial finalizado no impide desactivar: es justo para lo que sirve (un horario que
 * ya no se usa pero que no se puede eliminar sin perder quién lo tuvo).
 */
class ScheduleStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (Schedule $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (Schedule $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (Schedule $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (Schedule $record) => $record->is_active
                ? 'Los inactivos no se ofrecen al asignar horarios; conservan el historial. No se pueden desactivar con empleados asignados'
                : 'Vuelve a ofrecerlo al asignar horarios')
            ->authorize(fn (Schedule $record) => (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Schedule $record) => $record->is_active ? '¿Desactivar horario?' : '¿Activar horario?')
            ->modalDescription(fn (Schedule $record) => $record->is_active
                ? 'El horario "'.$record->name.'" ya no se ofrecerá al asignar horarios. El historial de asignaciones se conserva.'
                : 'El horario "'.$record->name.'" vuelve a ofrecerse al asignar horarios.')
            ->modalSubmitActionLabel(fn (Schedule $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (Schedule $record): void {
                if ($record->is_active && $record->deactivationBlockers() !== []) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar el horario')
                        ->body(ucfirst($record->deactivationBlockersSummary()).'.')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active ? 'Horario activado' : 'Horario desactivado')
                    ->body('El horario "'.$record->name.'" '.($record->is_active ? 'vuelve a ofrecerse' : 'ya no se ofrece').' al asignar horarios.')
                    ->send();
            });
    }
}
