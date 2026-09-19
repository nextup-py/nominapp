<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra registros de ausencias a partir de los días con status = 'absent'
 * ya existentes en attendance_days (generados por AttendanceDayWithEventsSeeder).
 *
 * Distribución de estados (determinista, basada en días transcurridos):
 *   - Últimos 7 días   → pending   (aún no revisadas)
 *   - Más de 7 días    → justified / unjustified alternando por employee_id
 *
 * Las ausencias revisadas incluyen reviewed_at, reviewed_by_id y review_notes.
 * El campo employee_deduction_id se deja en null: la deducción por ausencia
 * injustificada se aplica al procesar la nómina, no al registrar la ausencia.
 *
 * Las ausencias 'justified' vinculan un EmployeeLeave propio (creado aquí
 * mismo, aprobado, con start_date = end_date = la fecha de la ausencia) —
 * refleja la regla real de Absence::justify(), que exige vincular un permiso
 * aprobado (ver CLAUDE.md, "Módulo de Permisos y Licencias").
 *
 * Depende de: AttendanceDayWithEventsSeeder (attendance_days con status absent).
 */
class AbsenceSeeder extends Seeder
{
    /** Días recientes que quedan en estado pending sin revisar. */
    private const PENDING_DAYS_WINDOW = 7;

    public function run(): void
    {
        $userId = DB::table('users')->value('id');

        // Traer todos los días ausentes de empleados activos
        $absentDays = DB::table('attendance_days as ad')
            ->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->where('ad.status', 'absent')
            ->where('ad.is_holiday', false)
            ->where('e.status', 'active')
            ->orderBy('ad.date')
            ->get(['ad.id as attendance_day_id', 'ad.employee_id', 'ad.date']);

        if ($absentDays->isEmpty()) {
            $this->command->warn('No hay días con status absent. Ejecuta AttendanceDayWithEventsSeeder primero.');

            return;
        }

        $now = now();
        $today = Carbon::today();
        $rows = [];

        $pendingCount = 0;
        $justifiedCount = 0;
        $unjustifiedCount = 0;

        foreach ($absentDays as $day) {
            $date = Carbon::parse($day->date);
            $daysAgo = $date->diffInDays($today);
            $empId = $day->employee_id;
            $employeeLeaveId = null;

            if ($daysAgo <= self::PENDING_DAYS_WINDOW) {
                // Ausencia reciente: todavía no fue revisada
                $status = 'pending';
                $reviewedAt = null;
                $reviewedBy = null;
                $reviewNotes = null;
                $reportedAt = $date->copy()->setTime(8, ($empId + $date->day) % 31)->toDateTimeString();

                $pendingCount++;
            } else {
                // Ausencia más antigua: se alterna justified/unjustified por employee_id
                if ($empId % 2 === 0) {
                    $status = 'justified';
                    $reviewNotes = $this->justifiedNote($empId, $date);

                    // Absence::justify() exige un EmployeeLeave aprobado vinculado —
                    // se crea aquí uno de un solo día que cubre exactamente la ausencia.
                    $employeeLeaveId = DB::table('employee_leaves')->insertGetId([
                        'employee_id' => $empId,
                        'type' => $this->justifiedLeaveType($empId, $date),
                        'start_date' => $date->toDateString(),
                        'end_date' => $date->toDateString(),
                        'reason' => $reviewNotes,
                        'status' => 'approved',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $justifiedCount++;
                } else {
                    $status = 'unjustified';
                    $reviewNotes = 'No se recibió justificación en el plazo establecido.';
                    $unjustifiedCount++;
                }

                $reportedAt = $date->copy()->setTime(8, 0)->toDateTimeString();
                $reviewedAt = $date->copy()->addDays(2)->setTime(10, 0)->toDateTimeString();
                $reviewedBy = $userId;
            }

            $rows[] = [
                'employee_id' => $empId,
                'attendance_day_id' => $day->attendance_day_id,
                'status' => $status,
                'reason' => $this->absentReason($empId, $date),
                'reported_at' => $reportedAt,
                'reported_by_id' => $userId,
                'reviewed_at' => $reviewedAt,
                'reviewed_by_id' => $reviewedBy,
                'review_notes' => $reviewNotes,
                'documents' => null,
                'employee_deduction_id' => null,
                'employee_leave_id' => $employeeLeaveId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('absences')->insert($chunk);
        }

        $total = count($rows);
        $this->command->info(
            "Ausencias sembradas: $total total "
            ."($pendingCount pendientes, $justifiedCount justificadas, $unjustifiedCount injustificadas)."
        );
    }

    /**
     * Retorna un motivo de ausencia genérico determinista basado en employee_id y fecha.
     */
    private function absentReason(int $employeeId, Carbon $date): string
    {
        $reasons = [
            'Enfermedad — comunicó por teléfono.',
            'Trámite personal — sin comprobante.',
            'Problema de transporte — notificó al supervisor.',
            'Cita médica no programada.',
            'Emergencia familiar.',
            'No se comunicó con el empleador.',
        ];

        return $reasons[($employeeId + $date->day) % count($reasons)];
    }

    /**
     * Retorna una nota de revisión para ausencias justificadas.
     */
    private function justifiedNote(int $employeeId, Carbon $date): string
    {
        $notes = [
            'Presentó certificado médico dentro del plazo.',
            'Adjuntó constancia del trámite realizado.',
            'Supervisor confirmó la emergencia familiar.',
            'Presentó boleta de atención médica.',
        ];

        return $notes[($employeeId + $date->month) % count($notes)];
    }

    /**
     * Retorna el tipo de EmployeeLeave correspondiente a la nota de justificación
     * generada por justifiedNote() para el mismo employeeId y fecha (mismo índice).
     */
    private function justifiedLeaveType(int $employeeId, Carbon $date): string
    {
        $types = ['medical_leave', 'day_off', 'other', 'medical_leave'];

        return $types[($employeeId + $date->month) % count($types)];
    }
}
