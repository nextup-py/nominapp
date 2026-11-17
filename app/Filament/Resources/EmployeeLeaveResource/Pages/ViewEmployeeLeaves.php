<?php

namespace App\Filament\Resources\EmployeeLeaveResource\Pages;

use App\Filament\Resources\EmployeeLeaveResource;
use App\Models\Absence;
use App\Services\EmployeeLeaveDeductionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/** Página de detalle de una licencia de empleado con acciones de aprobación y rechazo. */
class ViewEmployeeLeaves extends ViewRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    /**
     * Devuelve las acciones disponibles en el encabezado según el estado de la licencia.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Aprobar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprobar Licencia')
                ->modalDescription(function () {
                    $record = $this->record;

                    if ($record->isPartialDay()) {
                        $base = "Se aprobará el permiso parcial ({$record->start_time} - {$record->end_time}).";
                        if ($record->generates_deduction) {
                            $amount = number_format($record->calculatedDeductionAmount(), 0, ',', '.');
                            $base .= " Se generará un descuento de Gs. {$amount} en la próxima nómina.";
                        }

                        return $base;
                    }

                    $count = Absence::where('employee_id', $record->employee_id)
                        ->whereHas('attendanceDay', fn ($q) => $q->whereBetween('date', [$record->start_date, $record->end_date]))
                        ->whereIn('status', ['pending', 'unjustified'])
                        ->count();

                    $base = 'Se aprobará la licencia del empleado.'.EmployeeLeaveResource::fullDayDeductionPreview($record);
                    if ($count > 0) {
                        $base .= " Se justificarán automáticamente {$count} ausencia(s) registrada(s) en el período.";
                    }

                    return $base;
                })
                ->modalSubmitActionLabel('Sí, aprobar')
                ->visible(fn () => $this->record->status === 'pending' && auth()->user()->can('approve_employee_leave'))
                ->action(function () {
                    $result = $this->record->approve(Auth::id());

                    $body = EmployeeLeaveResource::approvalBody($this->record, $result);

                    Notification::make()
                        ->success()
                        ->title('Licencia aprobada')
                        ->body($body)
                        ->send();

                    $this->refreshFormData(['status']);
                }),

            Action::make('reject')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rechazar Licencia')
                ->modalDescription('Se rechazará esta solicitud de licencia.')
                ->modalSubmitActionLabel('Sí, rechazar')
                ->visible(fn () => $this->record->status === 'pending' && auth()->user()->can('reject_employee_leave'))
                ->action(function () {
                    $this->record->reject();

                    Notification::make()
                        ->warning()
                        ->title('Licencia rechazada')
                        ->send();

                    $this->refreshFormData(['status']);
                }),

            Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->modalHeading('Anular licencia aprobada')
                ->modalDescription(function () {
                    $blocker = $this->record->cancellationBlocker();

                    if ($blocker !== null) {
                        return "No se puede anular: {$blocker}";
                    }

                    $absences = $this->record->absences()->where('status', 'justified')->count();
                    $deductions = app(EmployeeLeaveDeductionService::class)->deductionCount($this->record);

                    return "Se anulará la licencia. {$absences} ausencia(s) justificada(s) vuelven a «Por resolver» y {$deductions} descuento(s) se eliminan. No se genera ningún descuento por las ausencias: RR.HH. decide de nuevo.";
                })
                ->form([
                    Textarea::make('reason')
                        ->label('Motivo de la anulación')
                        ->required()
                        ->maxLength(500),
                ])
                ->modalSubmitActionLabel('Sí, anular')
                ->visible(fn () => $this->record->status === 'approved' && auth()->user()->can('cancel_employee_leave'))
                ->action(function (array $data) {
                    $result = $this->record->cancel(Auth::id(), $data['reason']);

                    $notification = Notification::make()->title($result['success'] ? 'Licencia anulada' : 'No se pudo anular');

                    $result['success']
                        ? $notification->success()->body("{$result['absences']} ausencia(s) volvieron a «Por resolver» y se eliminaron {$result['deductions']} descuento(s).")
                        : $notification->danger()->body($result['message']);

                    $notification->send();

                    $this->refreshFormData(['status']);
                }),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
