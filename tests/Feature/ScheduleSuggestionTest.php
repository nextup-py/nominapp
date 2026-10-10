<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleSuggestionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function suggestEmployee(): Employee
{
    static $n = 8900000;
    $n++;

    $company = Company::create(['name' => "EmpSg {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSg {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Sug', 'last_name' => "Test{$n}", 'ci' => (string) $n, 'email' => "sg{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

function suggestSchedule(string $name, string $in, string $out, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): Schedule
{
    $schedule = Schedule::create(['name' => $name, 'shift_type' => 'diurno']);
    foreach ($weekdays as $weekday) {
        $schedule->days()->create(['day_of_week' => $weekday, 'is_active' => true, 'start_time' => $in, 'end_time' => $out]);
    }

    return $schedule;
}

/** Jornadas completas de lunes a viernes recientes con la entrada y salida dadas. */
function suggestWorkedDays(Employee $employee, string $in, string $out, int $count = 5): void
{
    $date = Carbon::today();
    $made = 0;

    while ($made < $count) {
        $date = $date->copy()->subDay();

        if ($date->isWeekend()) {
            continue;
        }

        AttendanceDay::create([
            'employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present',
            'check_in_time' => $in, 'check_out_time' => $out,
        ]);
        $made++;
    }
}

it('sugiere el horario que mejor coincide con las marcaciones reales', function () {
    $employee = suggestEmployee();
    $morning = suggestSchedule('Mañana 07-15', '07:00', '15:00');
    $office = suggestSchedule('Oficina 09-16', '09:00', '16:00');
    suggestSchedule('Tarde 15-23', '15:00', '23:00');
    suggestWorkedDays($employee, '08:51:00', '14:56:00');

    $suggestion = app(ScheduleSuggestionService::class)->suggestFor($employee);

    expect($suggestion['schedule']->id)->toBe($office->id)
        ->and($suggestion['days'])->toBe(5)
        ->and($suggestion['deviation'])->toBeLessThanOrEqual(ScheduleSuggestionService::MAX_AVG_DEVIATION_MINUTES)
        ->and($morning->id)->not->toBe($office->id);
});

it('no sugiere nada con pocas jornadas completas o si ningún horario se parece', function () {
    $few = suggestEmployee();
    suggestSchedule('Oficina 09-16', '09:00', '16:00');
    suggestWorkedDays($few, '09:00:00', '16:00:00', 2);

    $far = suggestEmployee();
    suggestWorkedDays($far, '22:00:00', '23:30:00', 5);

    expect(app(ScheduleSuggestionService::class)->suggestFor($few))->toBeNull()
        ->and(app(ScheduleSuggestionService::class)->suggestFor($far))->toBeNull();
});

it('un horario que deja libres los días trabajados se penaliza', function () {
    $employee = suggestEmployee();
    suggestSchedule('Solo domingo 09-16', '09:00', '16:00', [7]);
    $weekdays = suggestSchedule('Semana 09-16', '09:00', '16:00', [1, 2, 3, 4, 5]);
    suggestWorkedDays($employee, '09:00:00', '16:00:00');

    expect(app(ScheduleSuggestionService::class)->suggestFor($employee)['schedule']->id)->toBe($weekdays->id);
});

it('la acción Asignar horario llega con el horario sugerido preseleccionado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = suggestEmployee();
    $office = suggestSchedule('Oficina 09-16', '09:00', '16:00');
    suggestWorkedDays($employee, '09:00:00', '16:00:00');

    $test = Livewire::test(ListEmployees::class)->mountTableAction('assign_schedule', $employee);
    $data = $test->instance()->mountedTableActionsData[array_key_last($test->instance()->mountedTableActionsData)];

    expect((int) $data['schedule_id'])->toBe($office->id);
});
