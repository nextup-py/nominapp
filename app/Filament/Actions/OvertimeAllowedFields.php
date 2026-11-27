<?php

namespace App\Filament\Actions;

use Filament\Forms\Components\Select;

/**
 * Selector Heredar/Sí/No del permiso de horas extra (empleado, cargo, sucursal, empresa). La cadena de herencia
 * vive en `Employee::overtimeAllowedSource()`; el valor general está en Configuración General.
 */
class OvertimeAllowedFields
{
    /** Selector que guarda null/true/false en `overtime_allowed`. */
    public static function select(string $helperText): Select
    {
        return Select::make('overtime_allowed')
            ->label('Horas extra')
            ->options([
                'inherit' => 'Heredar',
                'yes' => 'Sí, autorizadas',
                'no' => 'No autorizadas',
            ])
            ->default('inherit')
            ->native(false)
            ->selectablePlaceholder(false)
            ->formatStateUsing(fn ($state): string => MobileMarkingFields::toOption($state))
            ->dehydrateStateUsing(fn ($state): ?bool => MobileMarkingFields::fromOption($state))
            ->helperText($helperText);
    }

    /** Selector que guarda null/true/false en `overtime_request_required` (exigir pedido previo de horas extra). */
    public static function requestSelect(string $helperText): Select
    {
        return Select::make('overtime_request_required')
            ->label('Pedido previo de horas extra')
            ->options([
                'inherit' => 'Heredar',
                'yes' => 'Sí, exigir pedido previo',
                'no' => 'No exigir',
            ])
            ->default('inherit')
            ->native(false)
            ->selectablePlaceholder(false)
            ->formatStateUsing(fn ($state): string => MobileMarkingFields::toOption($state))
            ->dehydrateStateUsing(fn ($state): ?bool => MobileMarkingFields::fromOption($state))
            ->helperText($helperText);
    }
}
