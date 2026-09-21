<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit/Policies');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Registra una marcación directamente vía AttendanceDay::resolveForEvent(),
 * replicando la lógica que antes exponía el endpoint HTTP (ya eliminado)
 * `POST /marcar` de AttendanceFaceMarkController::store() — la marcación
 * online-síncrona quedó reemplazada por /api/v1/{terminal,mobile}/events/sync,
 * pero varios tests de regresión de la máquina de estados de asistencia siguen
 * necesitando una forma directa de "marcar un evento y ver si fue aceptado"
 * sin pasar por HTTP.
 *
 * @return array{ok: bool, event?: AttendanceEvent, day?: AttendanceDay, last_event?: string|null}
 */
function markAttendanceEvent(Employee $employee, string $eventType, ?Carbon\Carbon $recordedAt = null): array
{
    $now = $recordedAt ?? Carbon\Carbon::now(config('app.timezone'));

    try {
        return DB::transaction(function () use ($employee, $eventType, $now) {
            ['day' => $day, 'last' => $last, 'allowed' => $allowed] = AttendanceDay::resolveForEvent(
                $employee,
                $now,
                $eventType,
                lockForUpdate: true,
            );

            if (! $day) {
                $day = AttendanceDay::firstOrCreate(
                    ['employee_id' => $employee->id, 'date' => $now->toDateString()],
                    ['status' => 'present']
                );
            }

            if (! in_array($eventType, $allowed, true)) {
                throw new RuntimeException('not_allowed');
            }

            if ($day->status !== 'present') {
                $day->update(['status' => 'present']);
            }

            $event = $day->events()->create([
                'event_type' => $eventType,
                'recorded_at' => $now,
                'source' => 'manual',
            ]);

            return ['ok' => true, 'event' => $event, 'day' => $day, 'last_event' => $last?->event_type];
        });
    } catch (RuntimeException) {
        return ['ok' => false];
    }
}
