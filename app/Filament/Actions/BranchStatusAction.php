<?php

namespace App\Filament\Actions;

use App\Models\Branch;
use Filament\Notifications\Notification;

/**
 * Acción para activar o desactivar una sucursal, compartida por el listado (acción de tabla) y la
 * vista de detalle (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea
 * mientras la sucursal tenga empleados activos (`Branch::deactivationBlockers()`) y exige `update_branch`
 * (las acciones personalizadas de Filament no consultan la policy).
 */
class BranchStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (Branch $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (Branch $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (Branch $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (Branch $record) => $record->is_active
                ? 'Las sucursales inactivas no se ofrecen al asignar empleados o terminales; no se pueden desactivar con empleados activos'
                : 'Vuelve a ofrecer la sucursal al asignar empleados y terminales')
            ->authorize(fn (Branch $record) => (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Branch $record) => $record->is_active ? '¿Desactivar sucursal?' : '¿Activar sucursal?')
            ->modalDescription(fn (Branch $record) => static::description($record))
            ->modalSubmitActionLabel(fn (Branch $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (Branch $record): void {
                if ($record->is_active && ($blockers = $record->deactivationBlockers()) !== []) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar la sucursal')
                        ->body(static::blockersText($blockers).' Reasígnelos a otra sucursal primero.')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active ? 'Sucursal activada' : 'Sucursal desactivada')
                    ->body('La sucursal "'.$record->name.'" '.($record->is_active ? 'vuelve a ofrecerse' : 'ya no se ofrece').' al asignar empleados y terminales.')
                    ->send();
            });
    }

    /** Texto del modal: qué implica el cambio y, al desactivar, qué lo bloquea o qué queda activo. */
    private static function description(Branch $branch): string
    {
        if (! $branch->is_active) {
            return 'La sucursal "'.$branch->name.'" volverá a ofrecerse al asignar empleados y terminales.';
        }

        if (($blockers = $branch->deactivationBlockers()) !== []) {
            return static::blockersText($blockers).' Para desactivarla, reasígnelos a otra sucursal primero.';
        }

        $terminals = $branch->activeTerminalsCount();

        return 'La sucursal "'.$branch->name.'" dejará de ofrecerse al asignar empleados y terminales.'
            .($terminals > 0 ? " Sus {$terminals} terminales activos no se modifican." : '');
    }

    /**
     * @param  array<string, int>  $blockers
     */
    private static function blockersText(array $blockers): string
    {
        return 'La sucursal tiene '.collect($blockers)->map(fn (int $n, string $label) => "{$n} {$label}")->implode(', ').'.';
    }
}
