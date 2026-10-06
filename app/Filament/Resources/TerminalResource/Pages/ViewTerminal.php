<?php

namespace App\Filament\Resources\TerminalResource\Pages;

use App\Filament\Actions\TerminalCommandActions;
use App\Filament\Actions\TerminalLinkActions;
use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** Vista de detalle de una terminal con QR de acceso y acciones de ciclo de vida. */
class ViewTerminal extends ViewRecord
{
    protected static string $resource = TerminalResource::class;

    /**
     * Precarga las relaciones anidadas que usa el infolist en una sola query.
     */
    protected function resolveRecord(int|string $key): Terminal
    {
        return Terminal::with(['branch.company', 'installedBy'])->findOrFail($key);
    }

    /**
     * Al llegar desde la creación con `?provision=1` (ver
     * CreateTerminal::getRedirectUrl()), abre automáticamente el modal de
     * "Generar enlace de configuración" — evita que el admin tenga que volver
     * a la lista para conseguir el QR justo después de crear el terminal.
     *
     * No puede hacerse en mount(): `mountAction()` resuelve la acción contra
     * `cachedActions`, que recién se puebla en el hook de Livewire
     * `bootedInteractsWithHeaderActions()` — que corre después de mount().
     * Llamarlo ahí antes de tiempo hace que `getMountedAction()` no encuentre
     * la acción y la desmonte de inmediato, sin abrir el modal.
     */
    public function bootedInteractsWithHeaderActions(): void
    {
        parent::bootedInteractsWithHeaderActions();

        if (request()->boolean('provision')) {
            $this->mountAction('generate_setup_link');
        }
    }

    /**
     * Acciones del encabezado de la página de detalle.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label('Activar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->isInactive())
                ->requiresConfirmation()
                ->modalHeading('Activar terminal')
                ->modalDescription('La terminal volverá a estar disponible para marcaciones.')
                ->modalSubmitActionLabel('Sí, activar')
                ->action(function () {
                    $this->record->update(['status' => 'active']);
                    Notification::make()->success()->title('Terminal activada')->send();
                    $this->refreshFormData(['status']);
                }),

            Action::make('deactivate')
                ->label('Desactivar')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->visible(fn () => $this->record->isActive())
                ->requiresConfirmation()
                ->modalHeading('Desactivar terminal')
                ->modalDescription('La terminal dejará de aceptar marcaciones y mostrará una pantalla de fuera de servicio.')
                ->modalSubmitActionLabel('Sí, desactivar')
                ->action(function () {
                    $this->record->update(['status' => 'inactive']);
                    Notification::make()->warning()->title('Terminal desactivada')->send();
                    $this->refreshFormData(['status']);
                }),

            TerminalLinkActions::generateSetupLink(Action::class),
            TerminalLinkActions::showSetupLink(Action::class),
            EditAction::make()->label('Editar')->icon('heroicon-o-pencil-square')->color('primary'),

            ActionGroup::make(TerminalCommandActions::actions(Action::class))
                ->label('Comandos remotos')
                ->icon('heroicon-o-command-line')
                ->color('gray')
                ->button(),

            ActionGroup::make([
                Action::make('regenerate_code')
                    ->label('Cambiar URL del terminal')
                    ->tooltip('Cambia la URL pública del terminal — el dispositivo físico deberá reconfigurarse con la nueva URL')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Cambiar URL del terminal')
                    ->modalDescription('⚠️ Esto cambiará la URL pública de la terminal. El dispositivo físico dejará de funcionar hasta que sea reconfigurado con la nueva URL. Esto NO afecta el token de sincronización — el terminal seguirá conectado a la API mientras tanto.')
                    ->modalSubmitActionLabel('Sí, cambiar')
                    ->action(function () {
                        $newCode = Terminal::generateUniqueCode();
                        $this->record->update(['code' => $newCode]);

                        Notification::make()
                            ->warning()
                            ->title('URL del terminal actualizada')
                            ->body('Recordá reconfigurar el dispositivo físico con la nueva URL.')
                            ->send();

                        $this->refreshFormData(['code']);
                    }),

                TerminalLinkActions::openLinkWindow(Action::class),
                TerminalLinkActions::closeLinkWindow(Action::class),
                TerminalLinkActions::printSheet(Action::class),

                Action::make('revoke_token')
                    ->label('Desvincular dispositivo')
                    ->tooltip('Invalida el acceso del dispositivo a la sincronización offline — requerirá volver a vincular')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn () => $this->record->hasActiveSyncToken())
                    ->requiresConfirmation()
                    ->modalHeading('Desvincular dispositivo')
                    ->modalDescription('El dispositivo vinculado perderá acceso a la sincronización offline de inmediato. Para volver a usarlo habrá que vincularlo de nuevo (por código o con un enlace de configuración). El código y la URL del terminal no cambian.')
                    ->modalSubmitActionLabel('Sí, desvincular')
                    ->action(function () {
                        $this->record->revokeSyncTokens();
                        Notification::make()
                            ->success()
                            ->title('Dispositivo desvinculado')
                            ->body('El terminal deberá vincularse de nuevo para volver a sincronizar.')
                            ->send();
                    }),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->modalDescription('Esta acción no se puede deshacer. Las marcaciones ya registradas con este terminal no se eliminan, pero perderán la referencia a qué dispositivo físico las generó.')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-chevron-down')
                ->color('gray')
                ->button(),
        ];
    }
}
