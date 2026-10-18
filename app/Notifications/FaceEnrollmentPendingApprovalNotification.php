<?php

namespace App\Notifications;

use App\Filament\Resources\FaceEnrollmentResource;
use App\Models\FaceEnrollment;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando un empleado completa el autoenrolamiento
 * facial (`/registro-facial`) y el registro queda en `pending_approval`.
 * Sin esto, la única forma de enterarse de que hay una solicitud esperando
 * revisión era entrar a `FaceEnrollmentResource` a mirar manualmente.
 */
class FaceEnrollmentPendingApprovalNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly FaceEnrollment $enrollment,
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
        $employee = $this->enrollment->employee;
        $employeeLabel = $employee ? "{$employee->full_name} (CI: {$employee->ci})" : 'un empleado';

        return [
            ...FilamentNotification::make()
                ->title('Autoenrolamiento facial pendiente de aprobación')
                ->body("{$employeeLabel} completó la captura de su rostro y está esperando que apruebes o rechaces la solicitud.")
                ->icon('heroicon-o-face-smile')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Revisar solicitud')->url(FaceEnrollmentResource::getUrl('index')),
                ])
                ->getDatabaseMessage(),
            'enrollment_id' => $this->enrollment->id,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'attendance.face_enrollment';
    }
}
