<?php

namespace App\Filament\Resources\LiquidacionResource\Pages;

use App\Filament\Resources\LiquidacionResource;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Settings\PayrollSettings;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateLiquidacion extends CreateRecord
{
    protected static string $resource = LiquidacionResource::class;

    /**
     * Carga el formulario con los datos que trae la baja guiada (`employee_id`, `termination_date`, `termination_type`,
     * `termination_reason` en la URL); sin ellos queda como siempre.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(array_filter(
            request()->only(['employee_id', 'termination_date', 'termination_type', 'termination_reason']),
            fn ($value): bool => filled($value),
        ));

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $activeLiquidacion = Liquidacion::where('employee_id', $data['employee_id'])
            ->whereIn('status', ['draft', 'calculated'])
            ->first();

        if ($activeLiquidacion) {
            $statusLabel = $activeLiquidacion->isDraft() ? 'borrador' : 'calculada';

            Notification::make()
                ->danger()
                ->title('Liquidación activa existente')
                ->body("Este empleado ya tiene una liquidación en estado {$statusLabel}. Debe cerrarla o eliminarla antes de crear una nueva.")
                ->send();

            throw ValidationException::withMessages([
                'employee_id' => "Este empleado ya tiene una liquidación en estado {$statusLabel}.",
            ]);
        }

        $employee = Employee::find($data['employee_id']);

        // Advertencia de protección de maternidad (Ley 5508/15) — no bloquea, solo informa
        if ($employee->isUnderMaternityProtection()) {
            $until = $employee->maternity_protection_until->format('d/m/Y');
            Notification::make()
                ->warning()
                ->title('Empleada bajo protección de maternidad')
                ->body("Esta empleada goza de protección de maternidad hasta el {$until} (Ley N° 5508/15). El despido sin justa causa está prohibido durante este período. Si procede, asegúrese de contar con la autorización judicial correspondiente.")
                ->persistent()
                ->send();
        }

        // Advertencia si el empleado no tiene deducción IPS — no se descontará el 9% en la liquidación
        $ipsCode = app(PayrollSettings::class)->ips_deduction_code;
        $hasIps = $employee->deductions()
            ->where('code', $ipsCode)
            ->wherePivotNull('end_date')
            ->exists();

        if (! $hasIps) {
            Notification::make()
                ->warning()
                ->title('Empleado sin deducción IPS registrada')
                ->body("Este empleado no tiene la deducción IPS ({$ipsCode}) activa en su perfil. El aporte obrero del 9% no será descontado en la liquidación. Verifique si corresponde antes de continuar.")
                ->persistent()
                ->send();
        }

        $contract = $employee->activeContract;

        if (! $contract) {
            Notification::make()
                ->danger()
                ->title('Sin contrato activo')
                ->body('Este empleado no tiene un contrato activo. No se puede crear una liquidación.')
                ->send();

            throw ValidationException::withMessages([
                'employee_id' => 'Este empleado no tiene un contrato activo.',
            ]);
        }

        $salary = (float) ($contract->salary ?? 0);

        if ($salary <= 0) {
            Notification::make()
                ->danger()
                ->title('Salario inválido')
                ->body('El contrato activo del empleado tiene un salario de 0. No se puede crear una liquidación.')
                ->send();

            throw ValidationException::withMessages([
                'employee_id' => 'El contrato activo del empleado tiene un salario de 0.',
            ]);
        }

        if ($contract->salary_type === 'jornal') {
            $dailySalary = $salary;
            $baseSalary = round($salary * 30, 2);
        } else {
            $baseSalary = $salary;
            $dailySalary = round($salary / 30, 2);
        }

        $data['hire_date'] = $contract->start_date;
        $data['base_salary'] = $baseSalary;
        $data['daily_salary'] = $dailySalary;
        $data['salary_type'] = $contract->salary_type;
        $data['created_by_id'] = Auth::id();
        $data['status'] = 'draft';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
