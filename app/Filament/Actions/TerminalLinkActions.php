<?php

namespace App\Filament\Actions;

use App\Models\Terminal;
use App\Models\TerminalEvent;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Acciones de vinculación de un terminal — enlace de configuración con
 * vencimiento elegible, ventana de vinculación (individual y masiva) y hoja
 * de instalación imprimible. Se construyen con la misma lógica para la tabla
 * (`Filament\Tables\Actions\Action`) y para los encabezados de página
 * (`Filament\Actions\Action`): el llamador pasa la clase de acción.
 *
 * Abrir la ventana exige el mismo permiso que ya protege la gestión de
 * terminales (`update_terminal`); no hay un permiso nuevo.
 */
class TerminalLinkActions
{
    /** Indica si el usuario autenticado puede abrir/cerrar ventanas de vinculación. */
    public static function canManage(): bool
    {
        return TerminalPairingActions::canManage();
    }

    /**
     * Resuelve el terminal: en la tabla llega por inyección de `$record`; en el
     * encabezado de la página, del propio componente Livewire.
     *
     * @param  mixed  $record
     * @param  mixed  $livewire
     */
    private static function terminal($record, $livewire): Terminal
    {
        return $record instanceof Terminal ? $record : $livewire->getRecord();
    }

    /**
     * Genera el enlace de configuración y reemplaza el modal por el que lo muestra.
     * El token solo existe en claro en este momento (en la base queda su hash).
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function generateSetupLink(string $class): mixed
    {
        return $class::make('generate_setup_link')
            ->label('Generar enlace de configuración')
            ->tooltip('Enlace/QR de un solo uso para vincular el dispositivo a la sincronización offline')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading('Generar enlace de configuración')
            ->modalDescription(function ($record = null, $livewire = null): ?string {
                $terminal = static::terminal($record, $livewire);
                $notes = [];

                if ($terminal->hasPendingSetupLink()) {
                    $notes[] = 'Ya hay un enlace vigente: generar uno nuevo lo invalida. Por seguridad, un enlace ya generado no se puede volver a ver.';
                }
                if ($terminal->hasActiveSyncToken()) {
                    $notes[] = '⚠️ Este terminal ya está vinculado y sincronizando. Si otro dispositivo reclama el enlace, el acceso del dispositivo actual se revocará.';
                }

                return $notes ? implode(' ', $notes) : null;
            })
            ->modalSubmitActionLabel('Generar enlace')
            ->form([
                Select::make('expires_in')
                    ->label('Vigencia del enlace')
                    ->options(Terminal::SETUP_LINK_EXPIRY_OPTIONS)
                    ->default(30)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->rules(['in:'.implode(',', array_keys(Terminal::SETUP_LINK_EXPIRY_OPTIONS))])
                    ->helperText('Pasado ese tiempo el enlace deja de servir, aunque no se haya usado.'),
            ])
            ->action(function (array $data, $record = null, $livewire = null) use ($class) {
                $terminal = static::terminal($record, $livewire);
                $minutes = (int) $data['expires_in'];

                abort_unless(array_key_exists($minutes, Terminal::SETUP_LINK_EXPIRY_OPTIONS), 422);

                $token = $terminal->generateSetupToken($minutes);

                TerminalEvent::record($terminal, 'setup_link_generated', ['expires_in_minutes' => $minutes], Auth::id());

                static::replaceModal($livewire, $class, 'show_setup_link', $terminal, [
                    'url' => route('terminal.setup.show', ['code' => $terminal->code]).'#'.$token,
                    'expires_at' => $terminal->setup_token_expires_at->toIso8601String(),
                ]);
            });
    }

    /**
     * Modal con el enlace/QR recién generado. Solo es visible cuando se monta con
     * los argumentos del enlace (nunca aparece como opción del menú).
     *
     * @param  class-string  $class
     */
    public static function showSetupLink(string $class): mixed
    {
        return $class::make('show_setup_link')
            ->label('Enlace de configuración')
            ->visible(fn (array $arguments) => filled($arguments['url'] ?? null))
            ->modalHeading('Enlace de configuración del terminal')
            ->modalContent(fn (array $arguments) => view('filament.modals.terminal-setup-link', [
                'url' => $arguments['url'],
                'expiresAt' => Carbon::parse($arguments['expires_at']),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    /**
     * Abre la ventana de vinculación de un terminal.
     *
     * @param  class-string  $class
     */
    public static function openLinkWindow(string $class): mixed
    {
        return $class::make('open_link_window')
            ->label('Abrir ventana de vinculación')
            ->tooltip('Durante 15 minutos el primer dispositivo que pida vincularse queda vinculado sin aprobación')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(function ($record = null, $livewire = null): bool {
                $terminal = static::terminal($record, $livewire);

                return static::canManage() && $terminal->isActive() && ! $terminal->hasOpenLinkWindow();
            })
            ->requiresConfirmation()
            ->modalHeading('Abrir ventana de vinculación')
            ->modalDescription(fn ($record = null, $livewire = null) => 'Durante '.Terminal::LINK_WINDOW_MINUTES.' minutos, el primer dispositivo que abra la URL del terminal "'.static::terminal($record, $livewire)->name.'" y pida vincularse quedará vinculado sin que nadie apruebe su código, y la ventana se cierra. '
                .'Usala solo cuando el personal del local va a configurar el dispositivo: cualquiera que abra esa URL en ese lapso podría vincularse, así que abrila justo antes. Si el terminal ya tiene un dispositivo vinculado, será reemplazado.')
            ->modalSubmitActionLabel('Sí, abrir ventana')
            ->action(function ($record = null, $livewire = null) {
                abort_unless(static::canManage(), 403);

                $terminal = static::terminal($record, $livewire);
                $terminal->openLinkWindow(Auth::user());

                Notification::make()
                    ->success()
                    ->title('Ventana de vinculación abierta')
                    ->body('Vence a las '.$terminal->link_window_until->format('H:i').'. El primer dispositivo que pida vincularse queda vinculado.')
                    ->send();
            });
    }

    /**
     * Cierra la ventana de vinculación antes de que venza.
     *
     * @param  class-string  $class
     */
    public static function closeLinkWindow(string $class): mixed
    {
        return $class::make('close_link_window')
            ->label('Cerrar ventana de vinculación')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn ($record = null, $livewire = null) => static::canManage() && static::terminal($record, $livewire)->hasOpenLinkWindow())
            ->requiresConfirmation()
            ->modalHeading('Cerrar ventana de vinculación')
            ->modalDescription('Los dispositivos que pidan vincularse a partir de ahora volverán a necesitar la aprobación de un administrador.')
            ->modalSubmitActionLabel('Sí, cerrar ventana')
            ->action(function ($record = null, $livewire = null) {
                abort_unless(static::canManage(), 403);

                static::terminal($record, $livewire)->closeLinkWindow(Auth::id(), 'manual');

                Notification::make()->success()->title('Ventana de vinculación cerrada')->send();
            });
    }

    /**
     * Hoja de instalación imprimible (PDF) para el personal del local.
     *
     * @param  class-string  $class
     */
    public static function printSheet(string $class): mixed
    {
        return $class::make('print_install_sheet')
            ->label('Hoja de instalación (PDF)')
            ->tooltip('Instrucciones y QR para que el personal del local configure el dispositivo')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn ($record = null, $livewire = null) => route('terminals.install-sheet', static::terminal($record, $livewire)))
            ->openUrlInNewTab();
    }

    /** Acción masiva: abre la ventana de vinculación en los terminales activos seleccionados. */
    public static function openLinkWindowBulk(): BulkAction
    {
        return BulkAction::make('open_link_window_bulk')
            ->label('Abrir ventana de vinculación')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(fn () => static::canManage())
            ->requiresConfirmation()
            ->modalHeading('Abrir ventana de vinculación')
            ->modalDescription('Durante '.Terminal::LINK_WINDOW_MINUTES.' minutos, el primer dispositivo que pida vincularse a cada terminal seleccionado quedará vinculado sin aprobación. Se omiten los terminales inactivos o que ya tienen la ventana abierta.')
            ->modalSubmitActionLabel('Sí, abrir ventanas')
            ->deselectRecordsAfterCompletion()
            ->action(function ($records) {
                abort_unless(static::canManage(), 403);

                $opened = 0;
                $skipped = 0;

                foreach ($records as $terminal) {
                    if (! $terminal->isActive() || $terminal->hasOpenLinkWindow()) {
                        $skipped++;

                        continue;
                    }

                    $terminal->openLinkWindow(Auth::user());
                    $opened++;
                }

                Notification::make()
                    ->{$opened > 0 ? 'success' : 'warning'}()
                    ->title($opened > 0 ? "Ventanas de vinculación abiertas: {$opened}" : 'No se abrió ninguna ventana')
                    ->body($skipped > 0 ? "{$skipped} terminal(es) omitido(s) por estar inactivos o con la ventana ya abierta." : null)
                    ->send();
            });
    }

    /**
     * Reemplaza el modal abierto por otra acción (tabla o página según la clase).
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $arguments
     */
    private static function replaceModal($livewire, string $class, string $name, Terminal $terminal, array $arguments): void
    {
        if ($class === TableAction::class) {
            $livewire->replaceMountedTableAction($name, (string) $terminal->getKey(), $arguments);

            return;
        }

        $livewire->replaceMountedAction($name, $arguments);
    }
}
