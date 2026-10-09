<?php

namespace App\Filament\Resources\AttendanceDayResource\Pages;

use App\Filament\Resources\AttendanceDayResource;
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
                ->successNotificationTitle('Registro eliminado')
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
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
