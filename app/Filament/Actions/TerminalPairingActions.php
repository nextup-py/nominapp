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

        if ($record->replaces_active_device) {
            $lastHeartbeat = $terminal->last_heartbeat_at?->diffForHumans() ?? 'nunca';
            $lines[] = '⚠️ <strong>Reemplazará al dispositivo vinculado actualmente</strong> (último heartbeat: '.e($lastHeartbeat).'); su acceso se revocará.';
        }

        return new HtmlString(implode('<br>', $lines));
    }
}
