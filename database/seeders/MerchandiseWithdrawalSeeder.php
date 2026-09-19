<?php

namespace Database\Seeders;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra retiros de mercadería de ejemplo con los estados del ciclo de vida.
 *
 * Genera tres retiros representativos:
 *   - Pendiente de aprobación (con ítems, sin cuotas).
 *   - Aprobado con cuotas generadas (2 pagadas, resto pendientes).
 *   - Rechazado.
 *
 * Se asignan a empleados en índices 4, 5 y 6. Los índices 4 y 5 también
 * reciben un adelanto en AdvanceSeeder — es intencional (un empleado puede
 * tener un adelanto y un retiro de mercadería activos a la vez, no hay
 * regla de negocio que lo impida); solo el índice 0 (LoanSeeder) queda
 * reservado en exclusiva.
 */
class MerchandiseWithdrawalSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::where('status', 'active')->take(7)->get()->values();
        $adminId = DB::table('users')->value('id');
        $now = now();

        if ($employees->count() < 7) {
            $this->command->warn('Se necesitan al menos 7 empleados activos para el MerchandiseWithdrawalSeeder.');

            return;
        }

        DB::transaction(function () use ($employees, $adminId, $now): void {
            // ── 1. Retiro PENDIENTE (índice 4) ────────────────────────────────────
            $pendingId = DB::table('merchandise_withdrawals')->insertGetId([
                'employee_id' => $employees[4]->id,
                'total_amount' => 1_500_000,
                'installments_count' => 3,
                'installment_amount' => 500_000,
                'first_installment_days' => 30,
                'outstanding_balance' => 0,
                'status' => 'pending',
                'notes' => 'Solicitud de materiales de trabajo',
                'approved_at' => null,
                'approved_by_id' => null,
                'rejected_at' => null,
                'rejected_by_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('merchandise_withdrawal_items')->insert([
                [
                    'merchandise_withdrawal_id' => $pendingId,
                    'code' => 'HER-001',
                    'name' => 'Taladro inalámbrico',
                    'description' => 'Taladro 18V con set de brocas',
                    'price' => 900_000,
                    'quantity' => 1,
                    'subtotal' => 900_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'merchandise_withdrawal_id' => $pendingId,
                    'code' => 'HER-002',
                    'name' => 'Casco de seguridad',
                    'description' => 'Casco certificado ANSI/ISEA Z89.1',
                    'price' => 300_000,
                    'quantity' => 2,
                    'subtotal' => 600_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ── 2. Retiro APROBADO con cuotas (índice 5) ─────────────────────────
            $approvedDate = Carbon::now()->subMonths(2);
            $approvedId = DB::table('merchandise_withdrawals')->insertGetId([
                'employee_id' => $employees[5]->id,
                'total_amount' => 2_400_000,
                'installments_count' => 6,
                'installment_amount' => 400_000,
                'first_installment_days' => 30,
                'outstanding_balance' => 1_600_000,
                'status' => 'approved',
                'notes' => 'Equipo informático para teletrabajo',
                'approved_at' => $approvedDate->toDateString(),
                'approved_by_id' => $adminId,
                'rejected_at' => null,
                'rejected_by_id' => null,
                'created_at' => $approvedDate->copy()->subDays(2)->toDateTimeString(),
                'updated_at' => $approvedDate->toDateTimeString(),
            ]);

            DB::table('merchandise_withdrawal_items')->insert([
                [
                    'merchandise_withdrawal_id' => $approvedId,
                    'code' => 'TEC-001',
                    'name' => 'Monitor 24"',
                    'description' => 'Monitor Full HD 75Hz',
                    'price' => 1_400_000,
                    'quantity' => 1,
                    'subtotal' => 1_400_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'merchandise_withdrawal_id' => $approvedId,
                    'code' => 'TEC-002',
                    'name' => 'Teclado + mouse inalámbrico',
                    'description' => 'Set ergonómico Logitech MK295',
                    'price' => 500_000,
                    'quantity' => 1,
                    'subtotal' => 500_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'merchandise_withdrawal_id' => $approvedId,
                    'code' => 'TEC-003',
                    'name' => 'Hub USB-C 7 en 1',
                    'description' => 'Hub con HDMI, USB-A, lector SD',
                    'price' => 500_000,
                    'quantity' => 1,
                    'subtotal' => 500_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // Cuotas: 2 pagadas, 4 pendientes
            $installments = [];
            $baseDate = $approvedDate->copy()->addDays(30);
            for ($i = 1; $i <= 6; $i++) {
                $dueDate = $baseDate->copy()->addMonths($i - 1);
                $paid = $i <= 2;

                $installments[] = [
                    'merchandise_withdrawal_id' => $approvedId,
                    'installment_number' => $i,
                    'amount' => 400_000,
                    'due_date' => $dueDate->toDateString(),
                    'status' => $paid ? 'paid' : 'pending',
                    'employee_deduction_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('merchandise_withdrawal_installments')->insert($installments);

            // ── 3. Retiro RECHAZADO (índice 6) ────────────────────────────────────
            $rejectedDate = Carbon::now()->subDays(10);
            $rejectedWithdrawalId = DB::table('merchandise_withdrawals')->insertGetId([
                'employee_id' => $employees[6]->id,
                'total_amount' => 3_500_000,
                'installments_count' => 6,
                'installment_amount' => 0,
                'first_installment_days' => 30,
                'outstanding_balance' => 0,
                'status' => 'rejected',
                'notes' => 'Monto supera el límite permitido por política interna',
                'approved_at' => null,
                'approved_by_id' => null,
                'rejected_at' => $rejectedDate->toDateString(),
                'rejected_by_id' => $adminId,
                'created_at' => $rejectedDate->copy()->subDays(2)->toDateTimeString(),
                'updated_at' => $rejectedDate->toDateTimeString(),
            ]);

            DB::table('merchandise_withdrawal_items')->insert([
                [
                    'merchandise_withdrawal_id' => $rejectedWithdrawalId,
                    'code' => 'MOT-001',
                    'name' => 'Motocicleta 150cc',
                    'description' => 'Honda Wave 150S',
                    'price' => 3_500_000,
                    'quantity' => 1,
                    'subtotal' => 3_500_000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
}
