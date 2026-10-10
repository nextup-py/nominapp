<?php

use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedRegularShiftSettings(): void
{
    foreach ([
        'overtime_max_daily_hours' => 3,
        'overtime_multiplier_nocturno_holiday' => 2.6,
        'min_salary_monthly' => 2_550_328,
        'min_salary_daily_jornal' => 87_950,
        'family_bonus_percentage' => 5.0,
    ] as $name => $value) {
        DB::table('settings')->updateOrInsert(['group' => 'payroll', 'name' => $name], ['payload' => json_encode($value)]);
    }
}

/**
 * Día con doble turno (07-15 y 15:30-19:30) y 8 h esperadas: 4 h sobre el horario.
 */
function makeDoubleShiftDay(): AttendanceDay
{
    seedRegularShiftSettings();

    $company = Company::create(['name' => 'Empresa DT', 'ruc' => '4400000-1', 'employer_number' => 4400000]);
    $branch = Branch::create(['name' => 'Sucursal DT', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Doble', 'last_name' => 'Turno', 'ci' => '4400001', 'email' => 'dt@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => '2026-03-16', 'status' => 'absent',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ([['check_in', '07:00:00'], ['check_out', '15:00:00'], ['check_in', '15:30:00'], ['check_out', '19:30:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse('2026-03-16 '.$time),
        ]);
    }

    return $day->fresh();
}

function actingAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('detecta que el día tiene más de un turno', function () {
    $day = makeDoubleShiftDay();

    expect($day->hasMultipleShifts())->toBeTrue()
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('RR.HH. acepta el doble turno como jornada normal desde la tabla y se puede volver a revisar', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    Livewire::test(ListAttendanceDays::class)
        ->callTableAction('regular_shift', $day)
        ->assertHasNoTableActionErrors();

    $day->refresh();
    expect($day->second_shift_regular)->toBeTrue()
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and($day->overtime_approved)->toBeFalse();

    // Resuelto, el día sale de "Requieren atención": para deshacerlo se busca en "Todos".
    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->callTableAction('regular_shift', $day);

    $day->refresh();
    expect($day->second_shift_regular)->toBeFalse()
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('aceptar el doble turno revoca una aprobación de horas extra previa', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    $day->update(['overtime_approved' => true]);

    // Con las extras ya aprobadas el día no está en "Requieren atención": se abre "Todos".
    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')->callTableAction('regular_shift', $day);

    expect($day->fresh()->overtime_approved)->toBeFalse();
});

it('no ofrece la decisión en un día de un solo turno con horas extra', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    $day->events()->whereIn('recorded_at', ['2026-03-16 15:30:00', '2026-03-16 19:30:00'])->delete();
    $day->update(['extra_hours' => 1.5]);

    Livewire::test(ListAttendanceDays::class)
        ->assertTableActionHidden('regular_shift', $day->fresh());

    expect(AttendanceDayResource::getRegularShiftTableAction()->getName())->toBe('regular_shift');
});
