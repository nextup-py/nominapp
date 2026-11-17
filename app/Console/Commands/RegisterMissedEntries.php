<?php

namespace App\Console\Commands;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\User;
use App\Services\AbsenceAttendanceRegistrar;
use App\Services\OpenShiftCloser;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Carga de una vez la asistencia de quienes SÍ estuvieron presentes pero no pudieron marcar (terminal caído, olvido),
 * con las horas que confirmó la sucursal. Equivale a **Resolver → Registrar asistencia** en cada ausencia y usa el
 * mismo servicio: crea la marcación manual, recalcula la jornada y justifica la ausencia.
 *
 * Solo toca ausencias `pending` de la fecha indicada; omite y cuenta (nunca falla en silencio) a quien no tiene
 * ausencia ese día, ya la tiene resuelta, tiene nómina generada para esa fecha o pone una hora futura o inválida.
 */
class RegisterMissedEntries extends Command
{
    protected $signature = 'attendance:register-entries
                            {--entry=* : Entrada como «id_del_empleado=HH:MM» (repetible)}
                            {--exit=* : Salida opcional como «id_del_empleado=HH:MM» (repetible)}
                            {--date= : Fecha de las marcaciones (por defecto, hoy)}
                            {--reason=tech_failure : Motivo: no_mark, tech_failure, supervisor, data_error u other}
                            {--notes= : Nota adicional para la revisión de la ausencia}
                            {--user= : Usuario que figura como revisor (por defecto, el primer Super Admin)}
                            {--dry-run : Muestra qué se cargaría sin guardar nada}';

    protected $description = 'Carga entradas (y salidas) de quienes no pudieron marcar y justifica su ausencia';

    public function handle(AbsenceAttendanceRegistrar $registrar, OpenShiftCloser $closer): int
    {
        $entries = $this->parsePairs((array) $this->option('entry'));
        $exits = $this->parsePairs((array) $this->option('exit'));

        if ($entries === null || $exits === null) {
            return self::FAILURE;
        }

        if ($entries === [] && $exits === []) {
            $this->error('Indicá al menos una entrada: --entry=ID=HH:MM');

            return self::FAILURE;
        }

        $reason = (string) $this->option('reason');

        if (! array_key_exists($reason, AbsenceAttendanceRegistrar::REASONS)) {
            $this->error('Motivo inválido. Opciones: '.implode(', ', array_keys(AbsenceAttendanceRegistrar::REASONS)));

            return self::FAILURE;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $reviewerId = (int) ($this->option('user') ?: (User::role('Super Admin')->value('id') ?? User::query()->min('id')));

        if (! $dryRun && ! User::whereKey($reviewerId)->exists()) {
            $this->error('No hay un usuario para registrar como revisor. Indicá uno con --user=.');

            return self::FAILURE;
        }

        $done = 0;
        $skipped = 0;

        foreach (array_unique([...array_keys($entries), ...array_keys($exits)]) as $employeeId) {
            $reason_skip = $this->skipReason($employeeId, $date, $entries[$employeeId] ?? null, $exits[$employeeId] ?? null, $closer);
            $employee = Employee::find($employeeId);
            $label = $employee ? "{$employeeId} {$employee->first_name}" : (string) $employeeId;

            if (is_string($reason_skip)) {
                $this->warn("  omitido  {$label}: {$reason_skip}");
                $skipped++;

                continue;
            }

            /** @var Absence $absence */
            $absence = $reason_skip;
            $this->line(sprintf('  %s %s: entrada %s%s', $dryRun ? 'cargaría' : 'cargado ', $label, $entries[$employeeId] ?? '—', isset($exits[$employeeId]) ? ", salida {$exits[$employeeId]}" : ''));

            if (! $dryRun) {
                $registrar->register($absence, $entries[$employeeId] ?? null, $exits[$employeeId] ?? null, $reason, $this->option('notes') ?: null, $reviewerId);
            }

            $done++;
        }

        $this->info(($dryRun ? 'Se cargarían ' : 'Cargadas ')."{$done} jornada(s); omitidas: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Convierte «id=HH:MM» en [id => 'HH:MM']. Null (y un mensaje de error) si alguno tiene mal el formato.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>|null
     */
    private function parsePairs(array $values): ?array
    {
        $pairs = [];

        foreach ($values as $value) {
            if (! preg_match('/^(\d+)=([01]\d|2[0-3]):[0-5]\d$/', trim($value), $match)) {
                $this->error("Formato inválido: «{$value}». Usá ID=HH:MM, por ejemplo 23=06:35.");

                return null;
            }

            $pairs[(int) $match[1]] = substr(trim($value), strlen($match[1]) + 1);
        }

        return $pairs;
    }

    /**
     * La ausencia a regularizar o, si no corresponde, el motivo por el que se omite.
     */
    private function skipReason(int $employeeId, Carbon $date, ?string $entry, ?string $exit, OpenShiftCloser $closer): Absence|string
    {
        $absence = Absence::query()
            ->where('employee_id', $employeeId)
            ->whereHas('attendanceDay', fn ($q) => $q->where('date', $date->toDateString()))
            ->with('attendanceDay')
            ->first();

        return match (true) {
            $absence === null => 'no tiene ausencia en esa fecha',
            $absence->status !== 'pending' => "su ausencia ya está en estado {$absence->status}",
            $closer->isPayrollLocked($absence->attendanceDay) => 'tiene nómina generada para esa fecha',
            collect([$entry, $exit])->filter()->contains(fn (string $time): bool => Carbon::parse("{$date->toDateString()} {$time}")->isFuture()) => 'la hora es futura',
            default => $absence,
        };
    }
}
