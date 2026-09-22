<?php

namespace App\Filament\Resources\PayrollPeriodResource\Pages;

use App\Filament\Resources\PayrollPeriodResource;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPayrollPeriod extends EditRecord
{
    protected static string $resource = PayrollPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            Action::make('generate_payrolls')
                ->label('Generar Recibos')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generar Recibos de Nómina')
                ->modalDescription(
                    fn () => "¿Está seguro de generar los recibos de nómina para la planilla {$this->record->name}? ".
                        'Esta acción creará recibos para los empleados activos que aún no tengan uno en este período.'
                )
                ->action(function (PayrollService $payrollService) {
                    $result = $payrollService->generateForPeriod($this->record);
                    $count = $result['generated'];
                    $skipped = $result['skipped'];

                    if ($count > 0) {
                        $this->record->update([
                            'status' => 'processing',
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Recibos generados')
                            ->body("Se generaron exitosamente {$count} recibos de nómina.")
                            ->send();

                        $this->refreshFormData([
                            'status',
                            'updated_at',
                        ]);
                    } else {
                        Notification::make()
                            ->warning()
                            ->title('No se generaron recibos')
                            ->body('Todos los empleados activos ya tienen recibo en esta planilla.')
                            ->send();
                    }

                    if (! empty($skipped)) {
                        $lines = array_map(
                            fn ($s) => "{$s['name']} (CI: {$s['ci']}) — {$s['reason']}",
                            $skipped
                        );
                        $noun = count($skipped) === 1 ? 'empleado omitido' : 'empleados omitidos';

                        Notification::make()
                            ->warning()
                            ->title(count($skipped).' '.$noun)
                            ->body(implode("\n", $lines))
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => in_array($this->record->status, ['draft', 'processing']) && auth()->user()->can('generate_payrolls_period')),

            Action::make('regenerate_payrolls')
                ->label('Regenerar Recibos')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerar Todos los Recibos')
                ->modalDescription(function () {
                    $approved = $this->record->payrolls()->where('status', 'approved')->count();
                    $base = "¿Está seguro de regenerar TODOS los recibos de la planilla {$this->record->name}? ".
                        'Se recalcularán percepciones, deducciones, horas extras, ausencias, cuotas de préstamos, adelantos y cuotas de retiro de mercaderías.';
                    if ($approved > 0) {
                        $noun = $approved === 1 ? 'recibo aprobado será revertido' : 'recibos aprobados serán revertidos';
                        $base .= " Atención: {$approved} {$noun} a borrador y requerirán nueva aprobación.";
                    }

                    return $base;
                })
                ->modalSubmitActionLabel('Sí, regenerar')
                ->action(function (PayrollService $payrollService) {
                    $payrolls = $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->with('employee')->get();

                    if ($payrolls->isEmpty()) {
                        Notification::make()
                            ->warning()
                            ->title('Sin recibos para regenerar')
                            ->body('No hay recibos en estado borrador o aprobado para regenerar.')
                            ->send();

                        return;
                    }

                    $count = 0;
                    $failedEmployees = [];

                    foreach ($payrolls as $payroll) {
                        try {
                            if ($payroll->status === 'approved') {
                                $payroll->update(['status' => 'draft']);
                            }
                            $payrollService->regenerateForEmployee($payroll);
                            $count++;
                        } catch (\Throwable) {
                            $failedEmployees[] = $payroll->employee->full_name;
                        }
                    }

                    if (! empty($failedEmployees)) {
                        $names = implode(', ', $failedEmployees);
                        Notification::make()
                            ->warning()
                            ->title('Regeneración parcial')
                            ->body("Se regeneraron {$count} recibos. Fallaron: {$names}. Revise el log para más detalles.")
                            ->duration(10000)
                            ->send();
                    } else {
                        Notification::make()
                            ->success()
                            ->title('Recibos regenerados')
                            ->body("Se regeneraron exitosamente {$count} recibos de nómina.")
                            ->send();
                    }
                })
                ->visible(fn () => $this->record->status === 'processing' && $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists() && auth()->user()->can('generate_payrolls_period')),

            Action::make('revert_to_draft')
                ->label('Revertir a Borrador')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Revertir planilla a Borrador')
                ->modalDescription(function () {
                    $draftCount = $this->record->payrolls()->where('status', 'draft')->count();

                    return "Esta acción revertirá la planilla \"{$this->record->name}\" a estado Borrador ".
                        "y eliminará los {$draftCount} recibo(s) en borrador. ".
                        'Los recibos aprobados o pagados impiden esta acción.';
                })
                ->before(function (Action $action) {
                    $blockedCount = $this->record->payrolls()->whereIn('status', ['approved', 'paid'])->count();
                    if ($blockedCount > 0) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede revertir la planilla')
                            ->body("Hay {$blockedCount} recibo(s) aprobado(s) o pagado(s). Solo se puede revertir si todos los recibos están en borrador.")
                            ->duration(10000)
                            ->send();
                        $action->cancel();
                    }
                })
                ->action(function () {
                    $deleted = $this->record->payrolls()->where('status', 'draft')->delete();
                    $this->record->update(['status' => 'draft']);

                    Notification::make()
                        ->success()
                        ->title('Planilla revertida a Borrador')
                        ->body("Se eliminaron {$deleted} recibo(s) en borrador y la planilla volvió a estado Borrador.")
                        ->send();

                    $this->refreshFormData(['status', 'updated_at']);
                })
                ->visible(fn () => $this->record->status === 'processing'),

            Action::make('close_period')
                ->label('Cerrar Planilla')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Planilla de Nómina')
                ->modalDescription(
                    fn () => "¿Está seguro de cerrar la planilla {$this->record->name}? ".
                        'Una vez cerrada, no se podrán generar más recibos ni realizar modificaciones.'
                )
                ->before(function (Action $action) {
                    // Regla 1: no se puede cerrar si quedan recibos sin pagar.
                    $unpaidCount = $this->record->payrolls()->whereNot('status', 'paid')->count();

                    if ($unpaidCount > 0) {
                        Notification::make()
                            ->danger()
                            ->title('No se puede cerrar la planilla')
                            ->body("Hay {$unpaidCount} recibo(s) que aún no están en estado pagado. Pague todos los recibos antes de cerrar la planilla.")
                            ->duration(10000)
                            ->send();

                        $action->cancel();

                        return;
                    }

                    // Regla 2: no se puede cerrar si hay empleados activos sin recibo en esta planilla.
                    // Usa los mismos filtros que generateForPeriod() para garantizar consistencia.
                    $payrollEmployeeIds = $this->record->payrolls()->pluck('employee_id');

                    $missingCount = Employee::where('status', 'active')
                        ->whereHas('activeContract', fn ($q) => $q
                            ->where('payroll_type', $this->record->frequency)
                            ->whereNotNull('salary')
                        )
                        ->when($this->record->company_id, fn ($q) => $q->whereHas('branch', fn ($q) => $q->where('company_id', $this->record->company_id)))
                        ->whereNotIn('id', $payrollEmployeeIds)
                        ->count();

                    if ($missingCount > 0) {
                        Notification::make()
                            ->warning()
                            ->title('Hay empleados sin recibo')
                            ->body("Hay {$missingCount} empleado(s) activo(s) sin recibo en esta planilla. Use 'Generar Recibos' antes de cerrar.")
                            ->duration(10000)
                            ->send();

                        $action->cancel();
                    }
                })
                ->action(function () {
                    $this->record->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Planilla cerrada')
                        ->body("La planilla {$this->record->name} ha sido cerrada exitosamente.")
                        ->send();

                    return redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                // Visible solo cuando todos los recibos del período están pagados.
                ->visible(fn () => $this->record->status === 'processing'
                    && $this->record->payrolls()->exists()
                    && $this->record->payrolls()->whereNot('status', 'paid')->doesntExist()
                    && auth()->user()->can('close_payroll_period')),

            Action::make('reopen_period')
                ->label('Reabrir Planilla')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reabrir Planilla de Nómina')
                ->modalDescription(
                    fn () => "¿Está seguro de reabrir la planilla {$this->record->name}? ".
                        'Esto permitirá realizar modificaciones nuevamente.'
                )
                ->action(function () {
                    $this->record->update([
                        'status' => 'processing',
                        'closed_at' => null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Planilla reabierta')
                        ->body("La planilla {$this->record->name} ha sido reabierta exitosamente.")
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'closed_at',
                        'updated_at',
                    ]);
                })
                ->visible(fn () => $this->record->status === 'closed' && auth()->user()->can('reopen_payroll_period')),

            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'draft')
                ->requiresConfirmation()
                ->modalHeading('¿Eliminar período de nómina?')
                ->modalDescription(
                    fn () => "¿Está seguro de eliminar la planilla {$this->record->name}? ".
                        'Esta acción no se puede deshacer.'
                )
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title('Planilla eliminada')
                        ->body('La planilla ha sido eliminada correctamente.')
                ),
        ];
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Planilla actualizada')
            ->body("La planilla \"{$this->record->name}\" ha sido actualizada correctamente.");
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Limpiar espacios en blanco
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Si no se proporciona un nombre, generar uno automáticamente
        if (empty($data['name'])) {
            $data['name'] = PayrollPeriod::generateName(
                $data['frequency'],
                Carbon::parse($data['start_date']),
                Carbon::parse($data['end_date']),
            );
        }

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Evitar que se modifiquen planillas cerradas
        if ($this->record->status === 'closed') {
            Notification::make()
                ->warning()
                ->title('Planilla cerrada')
                ->body('Esta planilla está cerrada y no puede ser modificada.')
                ->persistent()
                ->send();
        }

        return $data;
    }
}
