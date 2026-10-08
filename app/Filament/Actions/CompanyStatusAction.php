<?php

namespace App\Filament\Actions;

use App\Models\Company;
use Filament\Notifications\Notification;

/**
 * Acción para activar o desactivar una empresa, compartida por el listado (acción de tabla) y la
 * vista de detalle (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea
 * mientras la empresa tenga empleados activos (`Company::deactivationBlockers()`).
 */
class CompanyStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (Company $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (Company $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (Company $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (Company $record) => $record->is_active
                ? 'Las empresas inactivas no aparecen en los selectores; no se pueden desactivar con empleados activos'
                : 'Vuelve a mostrar la empresa en los selectores')
            ->requiresConfirmation()
            ->modalHeading(fn (Company $record) => $record->is_active ? '¿Desactivar empresa?' : '¿Activar empresa?')
            ->modalDescription(fn (Company $record) => static::description($record))
            ->modalSubmitActionLabel(fn (Company $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (Company $record): void {
                if ($record->is_active && ($blockers = $record->deactivationBlockers()) !== []) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar la empresa')
                        ->body(static::blockersText($blockers).' Desvincúlelos o transfiéralos a otra empresa primero.')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title($record->is_active ? 'Empresa activada' : 'Empresa desactivada')
                    ->body('La empresa "'.$record->display_name.'" '.($record->is_active ? 'vuelve a aparecer' : 'ya no aparece').' en los selectores.')
                    ->send();
            });
    }

    /** Texto del modal: qué implica el cambio y, al desactivar, qué lo bloquea o qué queda activo. */
    private static function description(Company $company): string
    {
        if (! $company->is_active) {
            return 'La empresa "'.$company->display_name.'" volverá a aparecer en los selectores.';
        }

        if (($blockers = $company->deactivationBlockers()) !== []) {
            return static::blockersText($blockers).' Para desactivarla, desvincúlelos o transfiéralos primero.';
        }

        $terminals = $company->activeTerminalsCount();

        return 'La empresa "'.$company->display_name.'" dejará de aparecer en los selectores.'
            .($terminals > 0 ? " Sus {$terminals} terminales activos no se modifican." : '');
    }

    /**
     * @param  array<string, int>  $blockers
     */
    private static function blockersText(array $blockers): string
    {
        return 'La empresa tiene '.collect($blockers)->map(fn (int $n, string $label) => "{$n} {$label}")->implode(', ').'.';
    }
}
