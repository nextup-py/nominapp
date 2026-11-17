<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PayrollSettings extends Settings
{
    // Horas de trabajo por tipo de jornada
    public int $monthly_hours;

    public int $monthly_hours_nocturno;

    public float $monthly_hours_mixto;

    public int $daily_hours;

    public int $daily_hours_nocturno;

    public float $daily_hours_mixto;

    public int $days_per_month;

    // Multiplicadores de horas extra
    public float $overtime_multiplier_diurno;

    public float $overtime_multiplier_nocturno;

    public float $overtime_multiplier_holiday;

    public float $overtime_multiplier_nocturno_holiday;

    // Límites de horas extra
    public int $overtime_max_daily_hours;

    public int $overtime_max_weekly_hours;

    // Liquidación
    public float $ips_employee_rate;

    public string $ips_deduction_code;

    public int $indemnizacion_days_per_year;

    // Salarios mínimos legales vigentes (Ministerio de Trabajo)
    public int $min_salary_monthly;

    public int $min_salary_daily_jornal;

    // Bonificación familiar (Arts. 253-262 CLT)
    public float $family_bonus_percentage;

    // Vacaciones
    public int $vacation_min_consecutive_days;

    public int $vacation_min_years_service;

    public array $vacation_business_days;

    /** Cuenta los días de una vacación según el horario de cada empleado (sin horario, cae a `vacation_business_days`). */
    public bool $vacation_count_by_schedule;

    /** Días que una solicitud puede esperar sin decisión antes de avisar (0 = no avisar). */
    public int $vacation_alert_pending_days;

    /** Avisa de vacaciones aprobadas, con inicio hace más de una semana, que no figuran pagadas. */
    public bool $vacation_alert_unpaid_enabled;

    /** Avisa cada lunes de los empleados con el derecho anual completo sin tomar. */
    public bool $vacation_alert_unused_enabled;

    /** Días de anticipación para avisar que alguien cumple su primer año (0 = no avisar). */
    public int $vacation_alert_anniversary_days;

    // IRP — Impuesto a la Renta Personal (Ley 2421/04)
    public int $irp_annual_threshold;   // Umbral anual a partir del cual aplica IRP (Gs.)

    public float $irp_rate;             // Tasa del impuesto (%) sobre la renta gravada

    // Préstamos
    public int $max_loan_amount;                  // Monto máximo que se puede prestar a un empleado (Gs.)

    public float $loan_installment_cap_percent;   // % máximo del salario que puede representar la cuota mensual

    public int $loan_max_installments;            // Máximo de cuotas permitidas al crear un préstamo

    public float $loan_max_interest_rate;         // Tasa de interés anual máxima permitida (%)

    public int $loan_first_installment_days;      // Días desde la aprobación hasta el vencimiento de la primera cuota

    // Adelantos de salario
    public float $advance_max_percent;    // % máximo del salario que se puede adelantar por solicitud

    public int $advance_max_per_period;   // Máximo de adelantos por período de nómina (0 = sin límite)

    // Retiros de mercadería
    public int $merchandise_max_amount;           // Monto máximo por retiro (Gs.)

    public int $merchandise_max_installments;     // Cantidad máxima de cuotas permitidas

    public int $merchandise_first_installment_days; // Días desde la aprobación hasta el vencimiento de la primera cuota

    public static function group(): string
    {
        return 'payroll';
    }
}
