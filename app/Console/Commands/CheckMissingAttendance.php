<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckMissingAttendance extends Command
{
    // La firma del comando y su descripción.
    protected $signature = 'attendance:check-missing
                            {--date= : Fecha específica a procesar (formato Y-m-d). Si no se especifica, usa hoy}
                            {--dry-run : Ejecutar en modo prueba sin crear registros}';

    protected $description = 'Verifica empleados sin marcación y crea registros de ausencia si pasó el tiempo límite';

    /**
     * Ejecución del comando.
     *
     * @return void
     */
    public function handle()
    {
        // Obtener la fecha a procesar
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now();

        // Obtener la opción dry-run
        $dryRun = $this->option('dry-run');

        // Mensaje inicial
        $this->info('🔍 Verificando ausencias para: '.$date->format('d/m/Y H:i'));

        // Indicar modo dry-run
        if ($dryRun) {
            $this->warn('⚠️  Modo DRY-RUN activado - No se crearán registros');
        }

        // Obtener el umbral de tiempo para considerar ausencia
        $thresholdMinutes = app(GeneralSettings::class)->absence_threshold_minutes;
        $this->info("⏱️  Umbral configurado: {$thresholdMinutes} minutos después de la hora de entrada");

        // Contadores acumulados entre las fechas procesadas
        $totals = ['processed' => 0, 'created' => 0, 'on_leave' => 0, 'skipped' => 0];

        // Se procesa siempre la fecha pedida Y el día anterior. Necesario para
        // turnos que cruzan medianoche (ej. 22:00-06:00): el cron corre de
        // 06:00 a 20:00, así que un turno nocturno que arranca a las 22:00
        // nunca llega a pasar su propio umbral ese mismo día (ya terminó la
        // ventana del cron) y al día siguiente el comando históricamente solo
        // miraba "hoy", sin revisar si ayer quedó una ausencia sin detectar.
        // Mirar siempre un día atrás evita tener que detectar explícitamente
        // si el turno cruza medianoche: para un empleado de turno diurno
        // normal, el registro de ayer ya existe (se creó el mismo día dentro
        // de la ventana del cron), así que ese chequeo extra se salta casi
        // gratis por "ya tiene registro" (ver processEmployee()).
        foreach ([$date->copy()->subDay(), $date] as $dateToProcess) {
            $this->newLine();
            $this->info('📅 Procesando '.$dateToProcess->toDateString().'...');

            $counts = $this->processDate($dateToProcess, $thresholdMinutes, $dryRun);

            foreach ($totals as $key => $value) {
                $totals[$key] += $counts[$key];
            }
        }

        // Mostrar resumen de resultados
        $this->newLine();
        $this->info('✅ Proceso completado:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Empleados procesados', $totals['processed']],
                ['Ausencias creadas', $totals['created']],
                ['Licencias registradas', $totals['on_leave']],
                ['Omitidos (ya tienen registro o no aplica)', $totals['skipped']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Busca y procesa los empleados con horario/turno vigente para una fecha
     * puntual. Extraído de handle() para poder llamarse una vez por cada
     * fecha evaluada (la pedida y el día anterior).
     *
     * @return array{processed: int, created: int, on_leave: int, skipped: int}
     */
    protected function processDate(Carbon $date, int $thresholdMinutes, bool $dryRun): array
    {
        // Obtener empleados activos con horario u turno vigente para esta fecha — horario
        // fijo (asignación nueva o campo legacy) o rotación (patrón asignado u override
        // puntual). Antes de este fix, un empleado con rotación nunca generaba ausencia
        // automática por más que faltara a marcar: la consulta no lo incluía.
        $employees = Employee::where('status', 'active')
            ->where(fn ($q) => $q
                ->whereHas('scheduleAssignments', fn ($q) => $q->forDate($date))
                ->orWhereHas('schedule.days', fn ($q) => $q
                    ->where('day_of_week', $date->dayOfWeekIso)
                    ->where('is_active', true)
                )
                ->orWhereHas('rotationAssignments', fn ($q) => $q->forDate($date))
                ->orWhereHas('shiftOverrides', fn ($q) => $q->where('override_date', $date->toDateString()))
            )
            ->with([
                'scheduleAssignments.schedule.days',
                'schedule.days',
            ])
            ->get();

        // Mostrar cantidad de empleados encontrados
        $this->info('👥 Empleados activos con horario: '.$employees->count());

        // Contadores para estadísticas
        $counts = ['processed' => 0, 'created' => 0, 'on_leave' => 0, 'skipped' => 0];

        // Procesar cada empleado
        foreach ($employees as $employee) {
            $result = $this->processEmployee($employee, $date, $thresholdMinutes, $dryRun);

            $counts['processed']++;

            // Actualizar contadores según el resultado
            if ($result === 'created') {
                $counts['created']++;
            } elseif ($result === 'on_leave') {
                $counts['on_leave']++;
            } elseif ($result === 'skipped') {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * Procesa un empleado individual
     */
    protected function processEmployee(Employee $employee, Carbon $date, int $thresholdMinutes, bool $dryRun): string
    {
        // Verificar si ya existe un registro de asistencia para la fecha
        $existingRecord = AttendanceDay::where('employee_id', $employee->id)
            ->where('date', $date->toDateString())
            ->first();

        // Si ya existe un registro, omitir
        if ($existingRecord) {
            return 'skipped';
        }

        // Resuelve el turno/horario esperado para esta fecha — misma jerarquía que usa
        // el cálculo de horas trabajadas (rotación > horario fijo), para no duplicar la
        // lógica de resolución una tercera vez. check_in viene null si es franco
        // (rotación o día inactivo del horario fijo) o si no hay horario/turno vigente.
        $shiftData = AttendanceCalculator::resolveShiftDataFor($employee, $date);
        $expectedCheckIn = $shiftData['check_in'];

        if (! $expectedCheckIn) {
            return 'skipped';
        }

        // Calcular la hora límite (hora esperada + threshold)
        $expectedCheckInTime = Carbon::parse($date->toDateString().' '.$expectedCheckIn);
        $thresholdTime = $expectedCheckInTime->copy()->addMinutes($thresholdMinutes);

        // Solo crear ausencia si ya pasó el tiempo límite
        if (now()->lessThan($thresholdTime)) {
            // Aún no ha pasado el tiempo límite
            return 'skipped';
        }

        // Crear registro de ausencia
        if (! $dryRun) {
            try {
                $attendanceDay = AttendanceDay::create([
                    'employee_id' => $employee->id,
                    'date' => $date->toDateString(),
                    'status' => 'absent',
                    'is_calculated' => false,
                    'expected_check_in' => $expectedCheckIn,
                    'expected_check_out' => $shiftData['check_out'],
                    'expected_break_minutes' => $shiftData['break_minutes'],
                ]);

                // Aplicar cálculo para verificar vacaciones/permisos/feriados
                AttendanceCalculator::apply($attendanceDay);
                $attendanceDay->save();

                if ($attendanceDay->status === 'on_leave') {
                    Log::info("Licencia registrada automáticamente — {$employee->first_name} {$employee->last_name} CI {$employee->ci} ({$date->format('d/m/Y')})", [
                        'employee_id' => $employee->id,
                        'date' => $date->toDateString(),
                    ]);
                    $this->line("  ✓ Licencia registrada: {$employee->first_name} {$employee->last_name}");

                    return 'on_leave';
                }

                if ($attendanceDay->status !== 'absent') {
                    // Feriado o fin de semana — el registro existe pero no es una ausencia
                    $this->line("  · Omitido ({$attendanceDay->status}): {$employee->first_name} {$employee->last_name}");

                    return 'skipped';
                }

                Log::info("Ausencia creada automáticamente — {$employee->first_name} {$employee->last_name} CI {$employee->ci} ({$date->format('d/m/Y')}), entrada esperada: {$expectedCheckIn}", [
                    'employee_id' => $employee->id,
                    'date' => $date->toDateString(),
                    'expected_check_in' => $expectedCheckIn,
                    'threshold_time' => $thresholdTime->format('H:i'),
                ]);

                $this->line("  ✓ Ausencia creada: {$employee->first_name} {$employee->last_name} (Esperado: {$expectedCheckIn})");
            } catch (\Exception $e) {
                // Log de error si falla la creación
                Log::error('Error creando ausencia automática', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);

                // Mostrar error en consola
                $this->error("  ✗ Error creando ausencia para: {$employee->first_name} {$employee->last_name}");

                return 'skipped';
            }
        } else {
            // Modo dry-run: solo mostrar lo que se haría (no se puede distinguir ausencia vs licencia sin calcular)
            $this->line("  [DRY-RUN] Se crearía registro: {$employee->first_name} {$employee->last_name} (Esperado: {$expectedCheckIn})");
        }

        return 'created';
    }
}
