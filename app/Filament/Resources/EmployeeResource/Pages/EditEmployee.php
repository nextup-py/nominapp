<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Define las acciones del encabezado de la página de edición.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            ActionGroup::make([
                Action::make('capture_face')
                    ->label(fn () => $this->record->has_face ? 'Re-enrolar' : 'Enrolar')
                    ->icon('heroicon-o-camera')
                    ->color(fn () => $this->record->has_face ? 'warning' : 'success')
                    ->url(fn () => route('face.capture', $this->record))
                    ->visible(fn () => $this->record->status === 'active'),

                Action::make('generate_enrollment')
                    ->label('Generar enlace de enrolamiento')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->visible(fn () => $this->record->status === 'active')
                    ->requiresConfirmation()
                    ->modalHeading('Generar Enlace de Registro Facial')
                    ->modalDescription(function () {
                        $hours = app(GeneralSettings::class)->face_enrollment_expiry_hours;

                        return "Se generará un enlace temporal para que {$this->record->first_name} {$this->record->last_name} registre su rostro. El enlace expirará en {$hours} horas.";
                    })
                    ->modalSubmitActionLabel('Generar Enlace')
                    ->action(function () {
                        $settings = app(GeneralSettings::class);
                        $enrollment = FaceEnrollment::createForEmployee(
                            $this->record,
                            Auth::id(),
                            $settings->face_enrollment_expiry_hours
                        );

                        $url = route('face-enrollment.show', $enrollment->token);

                        Notification::make()
                            ->success()
                            ->title('Enlace generado — expira en '.$settings->face_enrollment_expiry_hours.'h')
                            ->body($url)
                            ->persistent()
                            ->actions([
                                NotificationAction::make('send_whatsapp')
                                    ->label('Enviar por WhatsApp')
                                    ->url('https://api.whatsapp.com/send?phone=595'.ltrim($this->record->phone ?? '', '0').'&text='.urlencode("Hola {$this->record->first_name}, usa este enlace para registrar tu rostro: {$url}"))
                                    ->openUrlInNewTab()
                                    ->visible(fn () => filled($this->record->phone)),
                            ])
                            ->send();
                    }),
            ])
                ->label('Más acciones')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Muestra el nombre del empleado en el título para no editar a otra persona por error.
     */
    public function getTitle(): string|Htmlable
    {
        return 'Editar: '.$this->record->full_name;
    }

    /**
     * Obtiene la URL de redirección después de guardar los cambios.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Bloquea el guardado si se intenta desactivar un empleado con contrato activo.
     * El flujo correcto para dar de baja a un empleado es procesar una Liquidación.
     */
    protected function beforeSave(): void
    {
        $newStatus = $this->data['status'] ?? null;

        if ($newStatus === 'inactive'
            && $this->record->status !== 'inactive'
            && $this->record->activeContract !== null
        ) {
            Notification::make()
                ->danger()
                ->title('No se puede desactivar')
                ->body('El empleado tiene un contrato activo. Procesar primero una Liquidación para cerrar el contrato y liquidar los haberes correctamente.')
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    /**
     * Modifica los datos del formulario antes de actualizar el registro. Mantiene el descriptor facial actual para evitar sobrescribirlo si no se captura uno nuevo.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = Employee::sanitizeFormData($data, isCreating: false);
        $data['face_descriptor'] = $this->record->face_descriptor;

        return $data;
    }

    /**
     * Obtiene la notificación que se muestra después de actualizar el registro.
     */
    protected function getSavedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title('Empleado actualizado')
            ->body('Los datos de '.$this->record->full_name.' han sido actualizados.');
    }
}
