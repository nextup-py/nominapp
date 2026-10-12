<?php

namespace App\Filament\Resources\AttendanceDayResource\Pages;

use App\Filament\Resources\AttendanceDayResource;
use App\Services\OpenShiftCloser;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** Página de edición de un registro de asistencia diaria. */
class EditAttendanceDay extends EditRecord
{
    protected static string $resource = AttendanceDayResource::class;

    /**
     * Acciones del encabezado: ver, aprobar HE, exportar PDF, calcular y eliminar.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            AttendanceDayResource::getApproveOvertimeAction(),
            AttendanceDayResource::getRegularShiftAction(),

            AttendanceDayResource::getExportPdfAction(),

            AttendanceDayResource::getCalculateAction(),

            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('¿Eliminar registro de asistencia?')
                ->modalDescription('¿Está seguro de que desea eliminar este registro? Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function (DeleteAction $action) {
                    if (AttendanceDayResource::blockedByPayroll($this->record)) {
                        $action->halt();
                    }
                })
                ->successNotificationTitle('Registro eliminado')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /** Impide guardar cambios si la fecha ya está cubierta por una nómina generada. */
    protected function beforeSave(): void
    {
        if (AttendanceDayResource::blockedByPayroll($this->record, app(OpenShiftCloser::class))) {
            $this->halt();
        }
    }

    /**
     * Notificación mostrada al guardar cambios exitosamente.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Asistencia actualizada')
            ->body("Los cambios para {$this->record->employee->full_name} ({$this->record->date->format('d/m/Y')}) han sido guardados.")
            ->duration(5000);
    }

    /**
     * Redirige al ViewRecord tras guardar.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
