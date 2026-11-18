<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Schemas\EmployeeForm;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Support\Facades\Auth;

class CreateEmployee extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EmployeeResource::class;

    /**
     * Modifica los datos del formulario antes de crear el registro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Employee::sanitizeFormData($data, isCreating: true);
    }

    /**
     * Pasos del asistente de alta (datos básicos, ubicación y horario, contrato).
     *
     * @return array<int, Step>
     */
    protected function getSteps(): array
    {
        return EmployeeForm::wizardSteps();
    }

    /**
     * Obtiene la URL de redirección después de crear el registro.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Obtiene la notificación que se muestra después de crear el registro.
     */
    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title('Empleado registrado')
            ->body('El empleado '.$this->record->full_name.' ha sido creado correctamente.');
    }

    /**
     * Crea el contrato y el horario iniciales si se completaron (las deducciones obligatorias las asigna el observer)
     * y avisa si el empleado quedó sin contrato.
     */
    protected function afterCreate(): void
    {
        $state = $this->data;

        // Crear contrato inicial si se completaron los campos mínimos
        if ((auth()->user()?->can('create_contract') ?? false) && filled($state['ic_salary'] ?? null) && filled($state['ic_position_id'] ?? null)) {
            Contract::create([
                'employee_id' => $this->record->id,
                'type' => $state['ic_type'] ?? 'indefinido',
                'start_date' => $state['ic_start_date'] ?? today(),
                'end_date' => $state['ic_end_date'] ?? null,
                'trial_days' => $state['ic_trial_days'] ?? 30,
                'salary_type' => $state['ic_salary_type'] ?? 'mensual',
                'salary' => $state['ic_salary'],
                'payroll_type' => $state['ic_payroll_type'] ?? 'monthly',
                'position_id' => $state['ic_position_id'],
                'department_id' => $state['ic_department_id'] ?? Position::find($state['ic_position_id'])?->department_id,
                'work_modality' => $state['ic_work_modality'] ?? 'presencial',
                'payment_method' => $state['ic_payment_method'] ?? 'debit',
                'status' => 'active',
                'created_by_id' => Auth::id(),
            ]);
        }

        // Asignar horario inicial si se eligió
        $scheduleId = $state['initial_schedule_id'] ?? null;
        if ($scheduleId) {
            $schedule = Schedule::find($scheduleId);
            if ($schedule) {
                ScheduleAssignmentService::assign(
                    employee: $this->record,
                    schedule: $schedule,
                    validFrom: Carbon::today(),
                );
            }
        }

        // Advertir si quedó sin contrato
        if ($this->record->contracts()->doesntExist()) {
            Notification::make()
                ->warning()
                ->title('Contrato pendiente')
                ->body('El empleado fue creado sin contrato. Recordá crearlo desde la pestaña "Contratos" para que aparezca el cargo y salario.')
                ->persistent()
                ->send();
        }
    }
}
