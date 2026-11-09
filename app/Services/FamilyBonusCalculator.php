<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Settings\PayrollSettings;

/**
 * Calcula la bonificación familiar según Arts. 253-262 del Código del Trabajo (Ley 213/93).
 *
 * Reglas:
 *  - Solo aplica a empleados con hijos menores de 18 años (al fin del período) registrados con
 *    cédula del menor o certificado de nacimiento adjunto.
 *  - Solo aplica si el salario base del contrato no supera dos salarios mínimos mensuales.
 *  - Monto por hijo = min_salary_monthly × (family_bonus_percentage / 100).
 *  - No es percepción salarial: no computa para IPS ni aguinaldo.
 */
class FamilyBonusCalculator
{
    /**
     * Calcula la bonificación familiar para un empleado en un período dado.
     *
     * @param  PayrollPeriod  $period  Su fecha de fin determina la edad de los hijos.
     * @return array{total: float, items: array}
     */
    public function calculate(Employee $employee, PayrollPeriod $period): array
    {
        $emptyResult = ['total' => 0.0, 'items' => []];

        // Usa la relación `children` precargada por PayrollService (evita 1 query por empleado);
        // si no está precargada, Eloquent la carga aquí. La edad se evalúa al fin del período,
        // no con la fecha de hoy, y solo cuentan hijos con cédula o certificado adjunto.
        $eligibleCount = $employee->eligibleChildrenAt($period->end_date)->count();

        if ($eligibleCount <= 0) {
            return $emptyResult;
        }

        $settings = app(PayrollSettings::class);

        $minSalary = $settings->min_salary_monthly;

        // Tope legal: dos salarios mínimos (Art. 254 CLT)
        $salaryBase = (float) ($employee->activeContract?->salary ?? 0);
        if ($salaryBase > $minSalary * 2) {
            return $emptyResult;
        }

        $bonusPerChild = round($minSalary * ($settings->family_bonus_percentage / 100), 2);
        $total = round($bonusPerChild * $eligibleCount, 2);

        $label = $eligibleCount === 1
            ? 'Bonificación Familiar (1 hijo menor de edad)'
            : "Bonificación Familiar ({$eligibleCount} hijos menores de edad)";

        return [
            'total' => $total,
            'items' => [
                ['description' => $label, 'amount' => $total],
            ],
        ];
    }
}
