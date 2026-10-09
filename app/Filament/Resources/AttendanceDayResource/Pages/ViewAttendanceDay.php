<?php

namespace App\Filament\Resources\AttendanceDayResource\Pages;

use App\Filament\Resources\AttendanceDayResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Página de visualización de un registro de asistencia diaria. */
class ViewAttendanceDay extends ViewRecord
{
    protected static string $resource = AttendanceDayResource::class;

    /**
     * Acciones del encabezado: editar, aprobar HE/tardanza, ajustar HE, exportar PDF y calcular.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),

            AttendanceDayResource::getApproveOvertimeAction(),
            AttendanceDayResource::getRegularShiftAction(),

            AttendanceDayResource::getApproveTardinessAction(),

            AttendanceDayResource::getAdjustExtraHoursAction(),

            AttendanceDayResource::getExportPdfAction(),

            AttendanceDayResource::getCalculateAction(),
        ];
    }
}
