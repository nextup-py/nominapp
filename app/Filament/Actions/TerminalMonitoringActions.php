<?php

namespace App\Filament\Actions;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración masiva del monitoreo de terminales (umbral de desconexión y horario de vigilancia).
 * Reutiliza los campos del formulario del terminal (`TerminalResource::monitoringFields()`) y el mismo
 * permiso que el resto de la gestión (`update_terminal`).
 */
class TerminalMonitoringActions
{
    /** Indica si el usuario autenticado puede configurar el monitoreo. */
    public static function canManage(): bool
    {
        return TerminalPairingActions::canManage();
    }

    /**
     * Acción masiva **Configurar monitoreo**: aplica el umbral y el horario a los terminales seleccionados,
     * o los devuelve a vigilancia 24/7 con el umbral general.
     */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('configure_monitoring_bulk')
            ->label('Configurar monitoreo')
            ->icon('heroicon-o-wifi')
            ->color('gray')
            ->visible(fn (): bool => static::canManage())
            ->modalHeading('Configurar monitoreo de los seleccionados')
            ->modalDescription(fn (Collection $records): string => "Se aplicará a {$records->count()} terminal(es), reemplazando su umbral y horario de vigilancia actuales. Cada uno puede ajustarse después por separado.")
            ->form([
                Toggle::make('reset')
                    ->label('Quitar el umbral y el horario propios')
                    ->helperText('Vuelven a vigilarse las 24 horas, todos los días, con el umbral general.')
                    ->live()
                    ->default(false),

                ...array_map(
                    fn ($field) => $field->visible(fn (Get $get): bool => ! $get('reset')),
                    TerminalResource::monitoringFields(),
                ),
            ])
            ->modalSubmitActionLabel('Aplicar')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => static::apply($records, $data));
    }

    /**
     * Aplica la configuración a los terminales y deja constancia en la bitácora de cada uno.
     *
     * @param  Collection<int, Terminal>  $terminals
     * @param  array<string, mixed>  $data
     */
    public static function apply(Collection $terminals, array $data): void
    {
        if (! static::canManage()) {
            return;
        }

        $reset = (bool) ($data['reset'] ?? false);
        $values = $reset
            ? ['stale_after_minutes' => null, 'watch_days' => null, 'watch_from' => null, 'watch_to' => null]
            : [
                'stale_after_minutes' => filled($data['stale_after_minutes'] ?? null) ? (int) $data['stale_after_minutes'] : null,
                'watch_days' => ! empty($data['watch_days']) ? array_map('intval', $data['watch_days']) : null,
                'watch_from' => filled($data['watch_from'] ?? null) ? $data['watch_from'] : null,
                'watch_to' => filled($data['watch_to'] ?? null) ? $data['watch_to'] : null,
            ];

        foreach ($terminals as $terminal) {
            $terminal->update($values);

            TerminalEvent::record(
                $terminal->fresh(),
                'monitoring_configured',
                ['message' => $reset ? 'Vigilancia 24/7 con el umbral general' : 'Vigilancia: '.$terminal->fresh()->watchScheduleLabel()],
                Auth::id(),
            );
        }

        Notification::make()
            ->success()
            ->title('Monitoreo configurado')
            ->body("Se actualizaron {$terminals->count()} terminal(es).")
            ->send();
    }
}
