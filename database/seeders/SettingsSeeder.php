<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los valores iniciales de configuración del sistema.
 *
 * Cubre los dos grupos de ajustes editables desde el panel:
 *   - GeneralSettings (group: "general") — datos de empresa, zona horaria, préstamos, contratos
 *   - PayrollSettings (group: "payroll") — jornadas, multiplicadores HE, IPS, vacaciones
 *
 * Los valores reflejan la legislación paraguaya vigente (Código Laboral — Ley 213/93).
 *
 * spatie/laravel-settings almacena cada propiedad como una fila independiente
 * con payload JSON. Se usa updateOrInsert para idempotencia.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGeneralSettings();
        $this->seedPayrollSettings();
    }

    /**
     * Siembra los ajustes generales del sistema.
     */
    private function seedGeneralSettings(): void
    {
        $this->upsertGroup('general', [
            // Configuración laboral
            'timezone' => 'America/Asuncion',
            'absence_threshold_minutes' => 30,

            // Contratos
            'contract_alert_days' => 30,

            // Reconocimiento facial
            'face_enrollment_expiry_hours' => 48,
            'face_threshold' => 0.45,
            'face_min_confidence_gap' => 0.1,
        ]);

        $this->command->info('GeneralSettings sembrados.');
    }

    /**
     * Siembra los parámetros de nómina según el Código Laboral paraguayo (Ley 213/93).
     *
     * Jornadas (Arts. 194–197):
     *   - Diurna:   8 hrs/día  × 30 días = 240 hrs/mes
     *   - Nocturna: 7 hrs/día  × 30 días = 210 hrs/mes
     *   - Mixta:    7.5 hrs/día × 30 días = 225 hrs/mes
     *
     * Horas extra (Art. 234): diurnas +50% → 1.5x | nocturnas 2.6x | feriado 2.0x
     * Límite HE (Art. 202): máximo 3 hrs extra por día.
     */
    private function seedPayrollSettings(): void
    {
        $this->upsertGroup('payroll', [
            // Jornada diurna (Art. 194)
            'monthly_hours' => 240,
            'daily_hours' => 8,
            'days_per_month' => 30,

            // Jornada nocturna (Art. 196)
            'monthly_hours_nocturno' => 210,
            'daily_hours_nocturno' => 7,

            // Jornada mixta (Art. 197)
            'monthly_hours_mixto' => 225.0,
            'daily_hours_mixto' => 7.5,

            // Multiplicadores de horas extra (Art. 234)
            'overtime_multiplier_diurno' => 1.5,  // +50% sobre hora diurna
            'overtime_multiplier_nocturno' => 2.6,  // 1.30 × 2.0 — sobre base diurna
            'overtime_multiplier_holiday' => 2.0,  // +100% sobre hora diurna
            'overtime_multiplier_nocturno_holiday' => 2.6,  // 1.30 × 2.0 — nocturna en feriado

            // Límites HE (Art. 202)
            'overtime_max_daily_hours' => 3,
            'overtime_max_weekly_hours' => 9,   // 3 hrs/día × 3 días

            // IRP — Impuesto a la Renta Personal (Ley 2421/04)
            'irp_annual_threshold' => 80_000_000, // ~80M Gs/año
            'irp_rate' => 10.0,        // 10% sobre renta gravada

            // IPS y liquidación
            'ips_employee_rate' => 9.0,
            'ips_deduction_code' => 'IPS001',
            'indemnizacion_days_per_year' => 15,

            // Salarios mínimos legales vigentes (Resolución MT 2024)
            'min_salary_monthly' => 2_550_328,  // Salario mínimo mensual
            'min_salary_daily_jornal' => 87_950,     // Salario mínimo diario para jornaleros

            // Bonificación familiar (Arts. 253-262 CLT) — 5% del salario mínimo mensual por hijo
            'family_bonus_percentage' => 5.0,

            // Vacaciones
            'vacation_min_consecutive_days' => 6,
            'vacation_min_years_service' => 1,
            'vacation_business_days' => [1, 2, 3, 4, 5, 6], // Lun–Sáb

            // Préstamos (Art. 245 CLT — cuota máxima 25% del salario)
            'max_loan_amount' => 5_000_000,
            'loan_installment_cap_percent' => 25.0,
            'loan_max_installments' => 60,
            'loan_max_interest_rate' => 100.0,
            'loan_first_installment_days' => 30,

            // Adelantos de salario
            'advance_max_percent' => 50.0, // % máximo del salario por solicitud
            'advance_max_per_period' => 0,    // 0 = sin límite

            // Retiro de mercaderías
            'merchandise_max_amount' => 10_000_000,
            'merchandise_max_installments' => 24,
            'merchandise_first_installment_days' => 30,
        ]);

        $this->command->info('PayrollSettings sembrados.');
    }

    /**
     * Inserta o actualiza todas las propiedades de un grupo de settings.
     *
     * Cada propiedad se almacena como fila individual con payload JSON,
     * según el formato interno de spatie/laravel-settings v2.
     *
     * @param  string  $group  Nombre del grupo (e.g., 'general')
     * @param  array<string, mixed>  $properties  Mapa nombre → valor
     */
    private function upsertGroup(string $group, array $properties): void
    {
        $now = now();

        foreach ($properties as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['group' => $group, 'name' => $name],
                [
                    'payload' => json_encode($value),
                    'locked' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
