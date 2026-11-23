<?php

namespace Database\Seeders;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Employee;
use App\Models\Terminal;
use App\Services\AttendanceCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arma el día de HOY de la demo respecto de la hora actual, para que el Monitor en vivo, «Por resolver» y «Mi equipo»
 * se vean con gente trabajando: unos entraron a tiempo, otros tarde, algunos están en el descanso, otros ya se fueron,
 * unos pocos no marcaron todavía y alguno de ayer quedó sin salida. Cada marcación sale por el terminal sano de su
 * sucursal (o por celular si la sucursal no tiene). Es determinista por empleado y día, e idempotente: `generate()`
 * borra el día de hoy y lo vuelve a armar, así que sirve también para refrescar la demo (`demo:refresh`).
 */
class DemoLiveAttendanceSeeder extends Seeder
{
    /** Marca de los fallos de marcación creados por este seeder (`metadata.demo`), para poder rehacerlos sin duplicar. */
    private const FAILURE_FLAG = 'demo';

    public function run(): void
    {
        $this->generate();
    }

    /**
     * Rehace el día de hoy y deja unas jornadas de ayer sin salida.
     *
     * @return array{marked: int, missing: int, open_yesterday: int}
     */
    public function generate(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->setTimezone(config('app.timezone'));
        $today = $now->copy()->startOfDay();
        $result = ['marked' => 0, 'missing' => 0, 'open_yesterday' => 0];

        $this->clearToday($today);

        if (! $today->isSunday() && ! DB::table('holidays')->where('date', $today->toDateString())->exists()) {
            $terminals = $this->markingTerminals();
            $missingEmployeeIds = [];

            Employee::query()->where('status', 'active')->with('branch')->orderBy('id')->get()->each(function (Employee $employee) use ($now, $today, $terminals, &$result, &$missingEmployeeIds) {
                $outcome = $this->seedEmployeeDay($employee, $now, $today, $terminals[$employee->branch_id] ?? null);

                $result['marked'] += $outcome === 'marked' ? 1 : 0;

                if ($outcome === 'missing') {
                    $result['missing']++;
                    $missingEmployeeIds[] = $employee->id;
                }
            });

            $this->seedMarkFailures($now, $terminals, $missingEmployeeIds);
        }

        $result['open_yesterday'] = $this->leaveYesterdayOpen($today);

        return $result;
    }

    /** Elimina las jornadas de hoy (y sus marcaciones, por cascada) y los fallos de marcación de la demo. */
    private function clearToday(Carbon $today): void
    {
        AttendanceDay::query()->where('date', $today->toDateString())->delete();
        AttendanceMarkFailure::query()->where('metadata->'.self::FAILURE_FLAG, true)->delete();
    }

    /**
     * Terminal sano de cada sucursal (en línea, en pestaña o sin cargador; no el dormido ni el sin vincular).
     *
     * @return array<int, Terminal>
     */
    private function markingTerminals(): array
    {
        return Terminal::query()
            ->where('device_notes', 'like', DemoTerminalSeeder::MARKER.'%')
            ->whereNotIn('device_notes', [DemoTerminalSeeder::MARKER.'stale', DemoTerminalSeeder::MARKER.'unlinked'])
            ->orderBy('id')
            ->get()
            ->unique('branch_id')
            ->keyBy('branch_id')
            ->all();
    }

    /**
     * Marcaciones de un empleado hasta la hora actual según su perfil del día.
     *
     * @return string `marked`, `missing` (empezó su turno y no marcó) o `idle` (todavía no le toca o no tiene turno)
     */
    private function seedEmployeeDay(Employee $employee, Carbon $now, Carbon $today, ?Terminal $terminal): string
    {
        $shift = AttendanceCalculator::resolveShiftDataFor($employee, $today);

        if (empty($shift['check_in']) || empty($shift['check_out'])) {
            return 'idle';
        }

        $in = $today->copy()->setTimeFromTimeString($shift['check_in']);
        $out = $today->copy()->setTimeFromTimeString($shift['check_out']);
        if ($out->lte($in)) {
            $out->addDay();
        }

        $hash = abs(crc32($employee->id.'-'.$today->toDateString())) % 100;
        $profile = match (true) {
            $hash < 62 => 'on_time',
            $hash < 80 => 'late',
            $hash < 86 => 'missing',
            default => 'on_time',
        };

        if ($now->lt($in->copy()->subMinutes(20))) {
            return 'idle';
        }

        if ($profile === 'missing') {
            return $now->gt($in->copy()->addMinutes(15)) ? 'missing' : 'idle';
        }

        $marks = $this->marksFor($shift, $in, $out, $hash, $profile, $now);

        if ($marks === []) {
            return 'idle';
        }

        $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $today->toDateString(), 'status' => 'present']);

        foreach ($marks as [$type, $at]) {
            AttendanceEvent::create([
                'attendance_day_id' => $day->id,
                'employee_id' => $employee->id,
                'event_type' => $type,
                'recorded_at' => $at,
                'source' => $terminal ? 'terminal' : 'mobile',
                'terminal_id' => $terminal?->id,
                'location' => $employee->branch?->coordinates,
            ]);
        }

        AttendanceCalculator::apply($day->fresh());
        $day->fresh()->save();

        return 'marked';
    }

    /**
     * Marcaciones del turno que ya ocurrieron a `$now`, en orden.
     *
     * @param  array{check_in: ?string, check_out: ?string, break_minutes: ?int}  $shift
     * @return array<int, array{0: string, 1: Carbon}>
     */
    private function marksFor(array $shift, Carbon $in, Carbon $out, int $hash, string $profile, Carbon $now): array
    {
        $marks = [['check_in', $profile === 'late' ? $in->copy()->addMinutes(8 + $hash % 20) : $in->copy()->subMinutes(2 + $hash % 7)]];

        $break = (int) ($shift['break_minutes'] ?? 0);
        if ($break > 0) {
            $breakStart = $in->copy()->addHours($break >= 45 ? 4 : 3)->addMinutes($hash % 5);
            $marks[] = ['break_start', $breakStart];
            $marks[] = ['break_end', $breakStart->copy()->addMinutes($break + $hash % 4)];
        }

        // Cada tanto alguien se va sin marcar la salida: aparece como «Sin salida» en el monitor.
        if ($hash % 17 !== 0) {
            $marks[] = ['check_out', $out->copy()->addMinutes($hash % 9)];
        }

        return array_values(array_filter($marks, fn (array $mark) => $mark[1]->lte($now)));
    }

    /**
     * Fallos de marcación de hoy para la bandeja: rostros no reconocidos y una salida sin entrada de quien no marcó.
     *
     * @param  array<int, Terminal>  $terminals
     * @param  array<int, int>  $missingEmployeeIds
     */
    private function seedMarkFailures(Carbon $now, array $terminals, array $missingEmployeeIds): void
    {
        $terminal = collect($terminals)->first();
        $branchId = $terminal?->branch_id;
        $failures = [
            ['face_no_match', null, 'check_in', 'No se encontró coincidencia entre el rostro capturado y los empleados de la sucursal.', 95],
            ['face_ambiguous', null, 'check_in', 'El rostro coincide con más de un empleado con poca diferencia de confianza.', 52],
            ['face_no_match', null, 'check_out', 'No se encontró coincidencia entre el rostro capturado y los empleados de la sucursal.', 24],
        ];

        if ($missingEmployeeIds !== []) {
            $failures[] = ['sync_conflict', $missingEmployeeIds[0], 'check_out', 'Salida sin entrada del día: el empleado no marcó al llegar.', 12];
        }

        foreach ($failures as [$type, $employeeId, $attempted, $message, $minutesAgo]) {
            $at = $now->copy()->subMinutes($minutesAgo);

            if ($at->lt($now->copy()->startOfDay())) {
                continue;
            }

            AttendanceMarkFailure::create([
                'mode' => 'terminal',
                'failure_type' => $type,
                'employee_id' => $employeeId,
                'branch_id' => $branchId,
                'attempted_event_type' => $attempted,
                'failure_message' => $message,
                'metadata' => array_filter([
                    self::FAILURE_FLAG => true,
                    'terminal_id' => $terminal?->id,
                    AttendanceMarkFailure::META_MISSING_ENTRY => $type === 'sync_conflict' ? true : null,
                ]),
                'ip_address' => '200.85.10.12',
                'occurred_at' => $at,
                'resolution_status' => 'pending',
            ]);
        }
    }

    /**
     * Quita la salida de ayer a tres personas con jornada completa: quedan en «Sin salida» para resolver en «Por resolver».
     *
     * @return int Cantidad de jornadas de ayer que quedaron abiertas.
     */
    private function leaveYesterdayOpen(Carbon $today): int
    {
        $yesterday = $today->copy()->subDay();

        if ($yesterday->isSunday()) {
            return 0;
        }

        $openNow = AttendanceDay::query()
            ->where('date', $yesterday->toDateString())
            ->where('status', 'present')
            ->whereHas('events', fn ($q) => $q->where('event_type', 'check_in'))
            ->whereDoesntHave('events', fn ($q) => $q->where('event_type', 'check_out'))
            ->count();

        $candidates = AttendanceDay::query()
            ->where('date', $yesterday->toDateString())
            ->where('status', 'present')
            ->whereHas('events', fn ($q) => $q->where('event_type', 'check_out'))
            ->orderBy('employee_id')
            ->take(max(0, 3 - $openNow))
            ->get();

        $open = $openNow;

        foreach ($candidates as $day) {
            $day->events()->where('event_type', 'check_out')->delete();
            AttendanceCalculator::apply($day->fresh());
            $day->fresh()->save();
            $open++;
        }

        return $open;
    }
}
