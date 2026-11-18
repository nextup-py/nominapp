<?php

namespace App\Filament\Actions;

use App\Exceptions\TerminalPairingException;
use App\Models\TerminalPairingRequest;
use App\Services\TerminalPairingService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;

/**
 * Acciones de fila para resolver solicitudes de vinculación de terminales —
 * compartidas por la bandeja de solicitudes (`TerminalPairingInbox`) y el
 * RelationManager de la vista del terminal. Requieren el mismo permiso que ya
 * protege la gestión de terminales (`update_terminal`); no hay un permiso nuevo.
 */
class TerminalPairingActions
{
    /** Indica si el usuario autenticado puede gestionar terminales. */
    public static function canManage(): bool
    {
        return auth()->user()?->can('update_terminal') ?? false;
    }

    /** Aprobar: el admin tipea el código que ve en la pantalla del terminal. */
    public static function approve(): Action
    {
        return Action::make('approve_pairing')
            ->label('Aprobar')
            ->tooltip('Aprobar la vinculación: el dispositivo recibe el acceso a la sincronización offline')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (TerminalPairingRequest $record) => $record->isPending() && static::canManage())
            ->modalHeading('Aprobar vinculación del dispositivo')
            ->modalDescription(fn (TerminalPairingRequest $record) => static::describe($record))
            ->modalSubmitActionLabel('Sí, aprobar')
            ->form([
                TextInput::make('code')
                    ->label('Código que muestra la pantalla del terminal')
                    ->helperText('Tipeá el código tal como lo ves en el dispositivo que vas a vincular. Si no coincide, no se aprueba.')
                    ->required()
                    ->maxLength(12)
                    ->autocomplete(false)
                    ->extraInputAttributes(['class' => 'uppercase tracking-widest']),
            ])
            ->action(function (TerminalPairingRequest $record, array $data, Action $action) {
                abort_unless(static::canManage(), 403);

                try {
                    app(TerminalPairingService::class)->approve($record, auth()->user(), $data['code']);
                } catch (TerminalPairingException $e) {
                    Notification::make()->danger()->title('No se pudo aprobar')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Vinculación aprobada')
                    ->body('El dispositivo recibirá el acceso en unos segundos.')
                    ->send();
            });
    }

    /** Rechazar la solicitud. */
    public static function deny(): Action
    {
        return Action::make('deny_pairing')
            ->label('Rechazar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (TerminalPairingRequest $record) => $record->isPending() && static::canManage())
            ->requiresConfirmation()
            ->modalHeading('Rechazar solicitud de vinculación')
            ->modalDescription('El dispositivo verá que la solicitud fue rechazada y podrá pedir un código nuevo.')
            ->modalSubmitActionLabel('Sí, rechazar')
            ->action(function (TerminalPairingRequest $record, Action $action) {
                abort_unless(static::canManage(), 403);

                try {
                    app(TerminalPairingService::class)->deny($record, auth()->user());
                } catch (TerminalPairingException $e) {
                    Notification::make()->danger()->title('No se pudo rechazar')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Solicitud rechazada')->send();
            });
    }

    /**
     * Texto del modal de aprobación: terminal, datos del dispositivo y aviso de
     * reemplazo, uno por línea. Escapa los valores (el user agent y el modelo los
     * reporta el dispositivo) antes de armar el HTML.
     */
    public static function describe(TerminalPairingRequest $record): HtmlString
    {
        $terminal = $record->terminal;
        $lines = [
            '<strong>Terminal:</strong> '.e($terminal->name).' ('.e($terminal->branch?->name).')',
            '<strong>Dispositivo:</strong> '.e($record->device_model_hint ?: 'modelo no detectado').' — IP '.e($record->ip_address ?: 'desconocida'),
            '<strong>Navegador:</strong> '.e($record->user_agent ?: 'no informado'),
        ];

        if ($elsewhere = $record->linkedElsewhere()) {
            $lines[] = '<strong>Atención: este equipo ya funciona como otro terminal:</strong> «'.e($elsewhere->name).'»'.($elsewhere->branch ? ' ('.e($elsewhere->branch->name).')' : '').'. Si lo aprobás acá, «'.e($elsewhere->name).'» se queda sin equipo y se verá desconectado. Pedile a quien lo usa que abra el enlace de ese terminal.';
        }

        if ($record->replaces_active_device) {
            $lines[] = '<strong>Dispositivo vinculado hoy:</strong> '.e($terminal->currentDeviceSummary());
            $lines[] = match ($record->same_device) {
                true => '<strong>Es el mismo dispositivo que está vinculado:</strong> perdió su acceso (por ejemplo, el navegador borró sus datos). Se puede aprobar.',
                false => '<strong>Es otro dispositivo distinto del que está vinculado.</strong> Si los dos siguen en uso se van a dejar sin acceso uno al otro.',
                default => null,
            };
            $lines[] = $terminal->isReportingNow() && $record->same_device !== true
                ? '<strong>Atención: este terminal está en línea en otro dispositivo.</strong> Aprobar este código lo dejará sin vínculo y volverá a pedir uno nuevo. Si hay dos equipos abiertos en la misma URL, dejar uno solo.'
                : '<strong>Reemplazará al dispositivo vinculado actualmente</strong>; su acceso se revocará.';
        }

        return new HtmlString(implode('<br>', array_filter($lines)));
    }
}
