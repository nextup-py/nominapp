<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeePerception;
use App\Models\PayrollPeriod;
use App\Settings\PayrollSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Verificación previa a generar la nómina de un período: cuenta los empleados y asignaciones que suelen
 * descubrirse a mano después (sin contrato, sin descuento de IPS, plus que no aportan al IPS, salarios bajo el
 * mínimo y pagos por débito sin cuenta). Solo informa: nunca bloquea la generación.
 */
class PayrollPreflightService
{
    /** Tipos de percepción cuyo aporte al IPS suele ser esperable (un plus fijo por cargo o responsabilidad). */
    private const IPS_EXPECTED_PERCEPTION_TYPES = ['salary', 'other'];

    /**
     * Avisos del período. Solo se devuelven los que tienen casos.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function check(PayrollPeriod $period): array
    {
        $checks = [
            ['key' => 'no_contract', 'label' => 'empleados activos sin contrato vigente (no entran en la nómina)', 'count' => $this->withoutContract($period)],
            ['key' => 'no_ips', 'label' => 'empleados con contrato sin descuento de IPS vigente', 'count' => $this->withoutIps($period)],
            ['key' => 'plus_without_ips', 'label' => 'percepciones salariales o de tipo Otro asignadas que no aportan al IPS', 'count' => $this->perceptionsWithoutIps($period)],
            ['key' => 'below_minimum', 'label' => 'contratos con salario por debajo del mínimo legal', 'count' => $this->belowMinimum($period)],
            ['key' => 'no_bank_account', 'label' => 'empleados con pago por débito bancario sin cuenta principal activa', 'count' => $this->withoutBankAccount($period)],
        ];

        return array_values(array_filter($checks, fn (array $check) => $check['count'] > 0));
    }

    /**
     * Empleados activos de la empresa del período (todas si el período no tiene empresa).
     */
    private function employees(PayrollPeriod $period): Builder
    {
        return Employee::query()
            ->where('status', 'active')
            ->when($period->company_id, fn (Builder $q) => $q->whereHas('branch', fn (Builder $q) => $q->where('company_id', $period->company_id)));
    }

    /**
     * Empleados que entrarán en la nómina: activos de la empresa con contrato vigente de la frecuencia del período.
     */
    private function payrollEmployees(PayrollPeriod $period): Builder
    {
        return $this->employees($period)
            ->whereHas('activeContract', fn (Builder $q) => $q->where('payroll_type', $period->frequency)->whereNotNull('salary'));
    }

    private function withoutContract(PayrollPeriod $period): int
    {
        return $this->employees($period)->whereDoesntHave('activeContract')->count();
    }

    private function withoutIps(PayrollPeriod $period): int
    {
        $code = app(PayrollSettings::class)->ips_deduction_code;

        return $this->payrollEmployees($period)
            ->whereDoesntHave('employeeDeductions', fn (Builder $q) => $q
                ->forPeriod($period->start_date, $period->end_date)
                ->whereHas('deduction', fn (Builder $d) => $d->where('code', $code)))
            ->count();
    }

    private function perceptionsWithoutIps(PayrollPeriod $period): int
    {
        return EmployeePerception::query()
            ->forPeriod($period->start_date, $period->end_date)
            ->whereIn('employee_id', $this->payrollEmployees($period)->select('employees.id'))
            ->whereHas('perception', fn (Builder $q) => $q
                ->whereIn('type', self::IPS_EXPECTED_PERCEPTION_TYPES)
                ->where('affects_ips', false))
            ->count();
    }

    private function belowMinimum(PayrollPeriod $period): int
    {
        return Contract::query()
            ->whereIn('status', ['active', 'suspended'])
            ->whereIn('employee_id', $this->payrollEmployees($period)->select('employees.id'))
            ->where(function (Builder $q) {
                $q->where(fn (Builder $m) => $m->where('salary_type', 'jornal')->where('salary', '<', Contract::getMinSalaryFor('jornal')))
                    ->orWhere(fn (Builder $m) => $m->where('salary_type', '!=', 'jornal')->where('salary', '<', Contract::getMinSalaryFor('mensual')));
            })
            ->count();
    }

    private function withoutBankAccount(PayrollPeriod $period): int
    {
        return $this->payrollEmployees($period)
            ->whereHas('activeContract', fn (Builder $q) => $q->where('payment_method', 'debit'))
            ->whereDoesntHave('bankAccounts', fn (Builder $q) => $q->where('is_primary', true)->where('status', 'active'))
            ->count();
    }
}
