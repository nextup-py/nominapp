<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Siembra deducciones de ejemplo para entorno de desarrollo/demo. */
class DeductionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $deductions = [
            // ── Legales ───────────────────────────────────────────────────────
            [
                'name' => 'Aporte IPS',
                'code' => 'IPS001',
                'type' => 'legal',
                'description' => 'Aporte al Instituto de Previsión Social (9% del salario)',
                'calculation' => 'percentage',
                'amount' => null,
                'percent' => 9.00,
                'is_mandatory' => true,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Impuesto a la Renta Personal (IRP)',
                'code' => 'IRP001',
                'type' => 'legal',
                'description' => 'Deducción de impuesto sobre la renta según la ley vigente',
                'calculation' => 'percentage',
                'amount' => null,
                'percent' => 8.00,
                'is_mandatory' => true,
                'affects_irp' => true,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            // ── Voluntarias ───────────────────────────────────────────────────
            [
                'name' => 'Seguro Médico Privado',
                'code' => 'SMP001',
                'type' => 'voluntary',
                'description' => 'Prima mensual del seguro médico',
                'calculation' => 'fixed',
                'amount' => 150000.00,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Aporte Sindical',
                'code' => 'AS001',
                'type' => 'voluntary',
                'description' => 'Aporte mensual al sindicato de trabajadores',
                'calculation' => 'fixed',
                'amount' => 50000.00,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            // ── Sistema — Préstamos y Adelantos ───────────────────────────────
            [
                'name' => 'Cuota de Préstamo',
                'code' => 'PRE001',
                'type' => 'loan',
                'description' => 'Descuento de cuota de préstamo otorgado por el empleador. El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cuota de Adelanto de Salario',
                'code' => 'ADE001',
                'type' => 'loan',
                'description' => 'Descuento de adelanto de salario (hasta 50% del salario del período). El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cuota de Retiro de Mercadería',
                'code' => 'MER001',
                'type' => 'loan',
                'description' => 'Descuento de cuota por retiro de mercadería a crédito. El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Ausencia Injustificada',
                'code' => 'AUS-INJ',
                'type' => 'other',
                'description' => 'Descuento por ausencia injustificada revisada por RR.HH. El monto se fija por día en cada deducción individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Suspensión Disciplinaria',
                'code' => 'SUS-DIS',
                'type' => 'other',
                'description' => 'Descuento por día de suspensión disciplinaria sin goce de sueldo. Generado desde la amonestación.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Descuento por Permiso Parcial',
                'code' => 'LIC001',
                'type' => 'voluntary',
                'description' => 'Descuento por horas de permiso parcial aprobado. El monto se calcula automáticamente según el salario y las horas tomadas.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
        ];

        foreach ($deductions as $deduction) {
            DB::table('deductions')->updateOrInsert(
                ['code' => $deduction['code']],
                array_merge($deduction, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
