<?php

namespace App\Filament\Actions;

use App\Models\Department;
use App\Models\Position;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Acción para activar o desactivar un departamento o un cargo, compartida por el listado (acción de tabla)
 * y la vista de detalle (acción de página): el llamador pasa la clase de acción. Desactivar se bloquea
 * mientras haya contratos vigentes (`deactivationBlockers()`) y exige el permiso `update` del modelo
 * (las acciones personalizadas de Filament no consultan la policy).
 */
class OrgUnitStatusAction
{
    /**
     * Construye la acción `toggle_status` con la clase de acción de tabla o de página.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function make(string $class): mixed
    {
        return $class::make('toggle_status')
            ->label(fn (Model $record) => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (Model $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (Model $record) => $record->is_active ? 'danger' : 'success')
            ->tooltip(fn (Model $record) => $record->is_active
                ? 'Los inactivos no se ofrecen al asignar contratos; no se pueden desactivar con contratos vigentes'
                : 'Vuelve a ofrecerlo al asignar contratos')
            ->authorize(fn (Model $record) => (bool) auth()->user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record) => ($record->is_active ? '¿Desactivar ' : '¿Activar ').static::noun($record).'?')
            ->modalDescription(fn (Model $record) => static::description($record))
            ->modalSubmitActionLabel(fn (Model $record) => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (Model $record): void {
                if ($record->is_active && ($count = static::currentContracts($record)) > 0) {
                    Notification::make()
                        ->danger()
                        ->title('No se puede desactivar el '.static::noun($record))
                        ->body(static::blockedText($record, $count))
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->success()
                    ->title(ucfirst(static::noun($record)).($record->is_active ? ' activado' : ' desactivado'))
                    ->body('El '.static::noun($record).' "'.$record->name.'" '.($record->is_active ? 'vuelve a ofrecerse' : 'ya no se ofrece').' al asignar contratos.')
                    ->send();
            });
    }

    /** «departamento» o «cargo» según el modelo. */
    private static function noun(Model $record): string
    {
        return $record instanceof Department ? 'departamento' : 'cargo';
    }

    /** Contratos vigentes que impiden desactivar. */
    private static function currentContracts(Model $record): int
    {
        /** @var Department|Position $record */
        return $record->activeContractsCount();
    }

    /** Texto del bloqueo con el singular o plural correcto. */
    private static function blockedText(Model $record, int $count): string
    {
        $contracts = $count === 1 ? '1 contrato vigente' : "{$count} contratos vigentes";

        return 'El '.static::noun($record)." tiene {$contracts}. ".($record instanceof Department
            ? 'Moverlos a otro departamento primero.'
            : 'Moverlos a otro cargo con Fusionar primero.');
    }

    /** Texto del modal: qué implica el cambio y, al desactivar, qué lo bloquea. */
    private static function description(Model $record): string
    {
        $noun = static::noun($record);

        if (! $record->is_active) {
            return 'El '.$noun.' "'.$record->name.'" volverá a ofrecerse al asignar contratos.';
        }

        if (($count = static::currentContracts($record)) > 0) {
            return static::blockedText($record, $count);
        }

        return 'El '.$noun.' "'.$record->name.'" dejará de ofrecerse al asignar contratos. Los contratos históricos y los reportes lo siguen mostrando.';
    }
}
