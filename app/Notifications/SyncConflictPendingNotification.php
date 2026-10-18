<?php

namespace App\Notifications;

use App\Filament\Resources\AttendanceMarkFailureResource;
use App\Models\AttendanceMarkFailure;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando se registra un conflicto de
 * sincronización offline (`AttendanceMarkFailure` con `failure_type:
 * sync_conflict`) — terminal o dispositivo sincronizó una marcación que ya no es
 * válida contra el estado actual del servidor. Sin esto, el registro queda
 * en `AttendanceMarkFailureResource` sin que nadie se entere hasta que un
 * admin entra a mirar manualmente — justo el gap identificado al probar en
 * producción.
 */
class SyncConflictPendingNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly AttendanceMarkFailure $failure,
    ) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` (no un
     * array plano) — la campanita del panel filtra por `data->format =
     * 'filament'`, que solo ese builder genera (ver
     * TerminalProvisionedNotification::toDatabase() para el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $employee = $this->failure->employee;
        $employeeLabel = $employee ? "{$employee->full_name} (CI: {$employee->ci})" : 'un empleado';
        $modeLabel = AttendanceMarkFailure::getModeLabel($this->failure->mode);

        return [
            ...FilamentNotification::make()
                ->title('Conflicto al sincronizar una marcación offline')
                ->body("Una marcación de {$employeeLabel} desde {$modeLabel} no se pudo aplicar al reconectarse — la secuencia ya no era válida en el servidor. Requiere revisión manual.")
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->actions([
                    FilamentAction::make('view')->label('Revisar conflicto')->url(AttendanceMarkFailureResource::getUrl('view', ['record' => $this->failure])),
                ])
                ->getDatabaseMessage(),
            'failure_id' => $this->failure->id,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'attendance.sync_conflict';
    }
}
