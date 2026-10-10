<?php

use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\AttendanceEventResource\Pages\ManageAttendanceEvents;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function eventWithDay(): AttendanceEvent
{
    $company = Company::create(['name' => 'Empresa EV', 'ruc' => '4700000-1', 'employer_number' => 4700000]);
    $branch = Branch::create(['name' => 'Sucursal EV', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Evento', 'last_name' => 'Dia', 'ci' => '4700001', 'email' => 'ev@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => Carbon::today()->subDay()->toDateString(), 'status' => 'present',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ]);

    return AttendanceEvent::create([
        'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_in',
        'recorded_at' => Carbon::today()->subDay()->setTime(7, 0),
    ]);
}

it('cada marcación enlaza a su jornada', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $event = eventWithDay();

    Livewire::test(ManageAttendanceEvents::class)
        ->assertTableActionVisible('view_day', $event)
        ->assertTableActionHasUrl('view_day', AttendanceDayResource::getUrl('view', ['record' => $event->attendance_day_id]), $event);
});

it('oculta el enlace a quien no puede ver jornadas', function () {
    Permission::firstOrCreate(['name' => 'view_any_attendance_event', 'guard_name' => 'web']);
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view_any_attendance_event')));
    $event = eventWithDay();

    Livewire::test(ManageAttendanceEvents::class)->assertTableActionHidden('view_day', $event);
});
