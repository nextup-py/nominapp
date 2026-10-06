<?php

namespace App\Filament\Actions;

use App\Models\Terminal;
use App\Models\TerminalCommand;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Facades\Auth;

/**
 * Comandos remotos para terminales (forzar sincronización, recargar, limpiar caché, reenviar
 * reporte). Se construyen para la tabla y para los encabezados de página (el llamador pasa la
 * clase de acción). Usan el mismo permiso que el resto de la gestión (`update_terminal`).
 */
class TerminalCommandActions
{
    /** Indica si el usuario autenticado puede enviar comandos a terminales. */
    public static function canManage(): bool
    {
        return TerminalPairingActions::canManage();
    }

    /**
     * Resuelve el terminal: en la tabla llega por inyección de `$record`; en el
     * encabezado de la página, del propio componente Livewire.
     */
    private static function terminal(mixed $record, mixed $livewire): Terminal
    {
        return $record instanceof Terminal ? $record : $livewire->getRecord();
    }

    /** Un terminal puede recibir comandos si está activo y vinculado. */
    private static function canReceive(Terminal $terminal): bool
    {
        return $terminal->isActive() && $terminal->hasActiveSyncToken();
    }

    /**
     * Una acción por comando, para meter en un grupo (tabla o página según la clase).
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     * @return array<int, mixed>
     */
    public static function actions(string $class): array
    {
        $icons = [
            TerminalCommand::FORCE_SYNC => 'heroicon-o-arrow-path',
            TerminalCommand::RELOAD => 'heroicon-o-arrow-path-rounded-square',
            TerminalCommand::CLEAR_CACHE => 'heroicon-o-trash',
            TerminalCommand::REPORT => 'heroicon-o-clipboard-document-check',
        ];

        return collect(TerminalCommand::getCommandLabels())
            ->map(fn (string $label, string $command) => $class::make('send_command_'.$command)
                ->label($label)
                ->tooltip(TerminalCommand::getCommandDescriptions()[$command])
                ->icon($icons[$command])
                ->color($command === TerminalCommand::CLEAR_CACHE ? 'warning' : 'gray')
                ->visible(fn ($record = null, $livewire = null) => static::canManage() && static::canReceive(static::terminal($record, $livewire)))
                ->requiresConfirmation()
                ->modalHeading($label)
                ->modalDescription(fn ($record = null, $livewire = null) => static::terminal($record, $livewire)->name.': '.TerminalCommand::getCommandDescriptions()[$command].' Se entrega en el próximo contacto del terminal (hasta ~90 segundos) y vence a los '.TerminalCommand::TTL_MINUTES.' minutos si no se conecta.')
                ->modalSubmitActionLabel('Sí, enviar')
                ->action(function ($record = null, $livewire = null) use ($command) {
                    abort_unless(static::canManage(), 403);

                    static::send(static::terminal($record, $livewire), $command);
                }))
            ->values()
            ->all();
    }

    /** Acción masiva: envía un mismo comando a todos los terminales seleccionados que puedan recibirlo. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('send_command_bulk')
            ->label('Enviar comando remoto')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn () => static::canManage())
            ->form([
                Select::make('command')
                    ->label('Comando')
                    ->options(TerminalCommand::getCommandLabels())
                    ->required()
                    ->native(false)
                    ->helperText('Se omiten los terminales inactivos o sin vincular.'),
            ])
            ->modalHeading('Enviar comando remoto')
            ->modalSubmitActionLabel('Sí, enviar')
            ->deselectRecordsAfterCompletion()
            ->action(function ($records, array $data) {
                abort_unless(static::canManage(), 403);

                $sent = 0;
                $skipped = 0;

                foreach ($records as $terminal) {
                    if (! static::canReceive($terminal)) {
                        $skipped++;

                        continue;
                    }

                    $terminal->sendCommand($data['command'], Auth::user());
                    $sent++;
                }

                Notification::make()
                    ->{$sent > 0 ? 'success' : 'warning'}()
                    ->title($sent > 0 ? "Comando enviado a {$sent} terminal(es)" : 'No se envió ningún comando')
                    ->body($skipped > 0 ? "{$skipped} terminal(es) omitido(s) por estar inactivos o sin vincular." : null)
                    ->send();
            });
    }

    /** Encola el comando y avisa al usuario; cualquier error de reglas se informa sin romper la acción. */
    private static function send(Terminal $terminal, string $command): void
    {
        try {
            $terminal->sendCommand($command, Auth::user());
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('No se pudo enviar el comando')->body($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Comando enviado')
            ->body('El terminal lo recibirá en su próximo contacto (hasta ~90 segundos). El resultado queda en la pestaña "Comandos".')
            ->send();
    }
}
