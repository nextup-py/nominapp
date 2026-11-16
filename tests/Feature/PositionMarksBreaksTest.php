<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleDay;
use App\Services\AttendanceCalculator;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/HasScheduledBreakBatchTest.php';

/** Cargo sin marcación de descanso (ej. repartidores): sin «Inicio descanso» y horas netas con el descanso del horario. */
function breakEmployee(bool $marksBreaks, int $breakMinutes = 60, ?Carbon $date = null): Employee
{
    $date ??= Carbon::parse('2026-08-24');
    $employee = makeBreakBatchEmployee(makeBreakBatchCompany());
    $employee->activeContract->position->update(['marks_breaks' => $marksBreaks]);

    $schedule = Schedule::create(['name' => 'Horario Marca '.$employee->id, 'shift_type' => 'diurno', 'description' => null]);
    $scheduleDay = ScheduleDay::create([
        'schedule_id' => $schedule->id, 'day_of_week' => $date->dayOfWeekIso, 'is_active' => true,
        'start_time' => '08:00', 'end_time' => '17:00',
    ]);
    if ($breakMinutes > 0) {
        ScheduleBreak::create([
            'schedule_day_id' => $scheduleDay->id, 'name' => 'Almuerzo',
            'start_time' => '12:00', 'end_time' => Carbon::parse('12:00')->addMinutes($breakMinutes)->format('H:i'),
        ]);
    }
    ScheduleAssignmentService::assign($employee, $schedule, $date->copy()->subYear());

    return $employee->fresh();
}

it('un cargo que no marca descanso no ofrece inicio de descanso aunque el horario lo tenga', function () {
    $day = Carbon::parse('2026-08-24');
    $marca = breakEmployee(true);
    $noMarca = breakEmployee(false);

    expect(AttendanceCalculator::hasScheduledBreak($marca, $day))->toBeTrue()
        ->and(AttendanceCalculator::hasScheduledBreak($noMarca, $day))->toBeFalse();
});

it('la versión batch coincide con la individual y el scope con marksBreaks()', function () {
    $day = Carbon::parse('2026-08-24');
    $employees = collect([breakEmployee(true), breakEmployee(false), breakEmployee(false, 0)]);

    $batch = AttendanceCalculator::hasScheduledBreakBatch(Employee::query()->whereIn('id', $employees->pluck('id'))->get(['id', 'schedule_id']), $day);

    foreach ($employees as $e) {
        expect($batch[$e->id])->toBe(AttendanceCalculator::hasScheduledBreak($e, $day))
            ->and(Employee::cargoWithoutBreaks()->whereKey($e->id)->exists())->toBe(! $e->marksBreaks());
    }
});

it('las horas netas descuentan el descanso del horario solo si el cargo no marca y no hubo descanso real', function () {
    $day = Carbon::parse('2026-08-24');

    $calc = function (Employee $e, bool $realBreak = false) use ($day): AttendanceDay {
        $d = AttendanceDay::create(['employee_id' => $e->id, 'date' => $day->toDateString(), 'status' => 'present']);
        $events = [['check_in', '08:00'], ['check_out', '17:00']];
        if ($realBreak) {
            array_splice($events, 1, 0, [['break_start', '12:00'], ['break_end', '12:20']]);
        }
        foreach ($events as $i => [$type, $time]) {
            AttendanceEvent::create([
                'attendance_day_id' => $d->id, 'employee_id' => $e->id, 'event_type' => $type,
                'recorded_at' => Carbon::parse($day->toDateString().' '.$time), 'source' => 'terminal',
                'client_event_id' => (string) Str::uuid(),
            ]);
        }
        AttendanceCalculator::apply($d->fresh());

        return $d->fresh();
    };

    $noMarca = $calc(breakEmployee(false));
    $marca = $calc(breakEmployee(true));
    $conBreakReal = $calc(breakEmployee(false), true);

    expect((float) $noMarca->total_hours)->toBe(9.0)
        ->and((float) $noMarca->net_hours)->toBe(8.0)
        ->and((float) $noMarca->extra_hours)->toBe(0.0)
        ->and((float) $marca->net_hours)->toBe(9.0)
        ->and((float) $conBreakReal->net_hours)->toBe(round(9 - 20 / 60, 2));
});
