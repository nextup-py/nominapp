<?php

use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Resources\AttendanceEventResource;
use App\Filament\Resources\AttendanceEventResource\Pages\ManageAttendanceEvents;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceInboxService;
use App\Services\LocationRadiusService;
use App\Services\MobileEventSyncService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Punto de referencia: Asunción. ~0.001° de latitud ≈ 111 m. */
const LOC_LAT = -25.2867;
const LOC_LNG = -57.6478;

function locationEmployee(array $branch = [], array $employee = []): Employee
{
    static $n = 6600000;
    $n++;

    $company = Company::create(['name' => "EmpLR {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branchModel = Branch::create(['name' => "SucLR {$n}", 'company_id' => $company->id, ...$branch]);

    return Employee::create([
        'first_name' => 'Luis', 'last_name' => "Radio{$n}", 'ci' => (string) $n, 'email' => "lr{$n}@test.com",
        'branch_id' => $branchModel->id, 'status' => 'active', ...$employee,
    ])->fresh();
}

it('calcula la distancia en metros entre dos puntos', function () {
    expect(LocationRadiusService::distanceMeters(LOC_LAT, LOC_LNG, LOC_LAT, LOC_LNG))->toBe(0.0)
        ->and(LocationRadiusService::distanceMeters(LOC_LAT, LOC_LNG, LOC_LAT + 0.001, LOC_LNG))->toBeBetween(105, 117);
});

it('un empleado sin punto ni sucursal con control es de campo y no se evalúa', function () {
    $employee = locationEmployee();

    expect(LocationRadiusService::resolve($employee))->toBeNull()
        ->and(LocationRadiusService::evaluate($employee, ['lat' => 1, 'lng' => 1]))->toBe([])
        ->and(LocationRadiusService::evaluate($employee, null))->toBe([]);
});

it('usa el punto del empleado con el radio general por defecto y lo marca fuera de radio', function () {
    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]);

    $rule = LocationRadiusService::resolve($employee);
    expect($rule['source'])->toBe('employee')->and($rule['radius'])->toBe(150);

    $inside = LocationRadiusService::evaluate($employee, ['lat' => LOC_LAT + 0.0005, 'lng' => LOC_LNG]);
    expect($inside)->toHaveKey('location_distance_m')->and($inside)->not->toHaveKey('location_flag');

    $outside = LocationRadiusService::evaluate($employee, ['lat' => LOC_LAT + 0.01, 'lng' => LOC_LNG]);
    expect($outside['location_flag'])->toBe('outside_radius')->and($outside['location_review'])->toBe('pending');
});

it('el radio propio del empleado gana sobre el general', function () {
    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG], 'location_radius_m' => 50]);

    expect(LocationRadiusService::resolve($employee)['radius'])->toBe(50)
        ->and(LocationRadiusService::evaluate($employee, ['lat' => LOC_LAT + 0.001, 'lng' => LOC_LNG])['location_flag'])->toBe('outside_radius');
});

it('la sucursal solo controla si activó el control y tiene coordenadas', function () {
    $coords = ['lat' => LOC_LAT, 'lng' => LOC_LNG];

    expect(LocationRadiusService::resolve(locationEmployee(['coordinates' => $coords])))->toBeNull()
        ->and(LocationRadiusService::resolve(locationEmployee(['location_control' => true])))->toBeNull();

    $rule = LocationRadiusService::resolve(locationEmployee(['coordinates' => $coords, 'location_control' => true, 'location_radius_m' => 300]));
    expect($rule['source'])->toBe('branch')->and($rule['radius'])->toBe(300);
});

it('sin ubicación y con control queda señalada como sin ubicación', function () {
    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]);

    expect(LocationRadiusService::evaluate($employee, null))->toBe(['location_flag' => 'no_location', 'location_review' => 'pending']);
});

it('la sincronización del celular guarda la distancia y el señalamiento en la marcación', function () {
    enableMobileMarking();
    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]);

    app(MobileEventSyncService::class)->syncBatch($employee, [[
        'client_event_id' => (string) Str::uuid(), 'event_type' => 'check_in', 'recorded_at' => now()->subMinutes(5)->toIso8601String(),
        'location' => ['lat' => LOC_LAT + 0.02, 'lng' => LOC_LNG],
    ]]);

    $event = AttendanceEvent::where('employee_id', $employee->id)->first();
    expect($event->location_flag)->toBe('outside_radius')
        ->and($event->location_distance_m)->toBeGreaterThan(2000)
        ->and($event->location_review)->toBe('pending');
});

function locationFlaggedEvent(Employee $employee): AttendanceEvent
{
    $day = AttendanceDay::firstOrCreate(
        ['employee_id' => $employee->id, 'date' => now()->toDateString()],
        ['status' => 'present', 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false],
    );

    return AttendanceEvent::create([
        'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'employee_name' => $employee->full_name,
        'event_type' => 'check_in', 'recorded_at' => now()->subMinutes(2), 'source' => 'mobile',
        'location_distance_m' => 900, 'location_flag' => 'outside_radius', 'location_review' => 'pending',
    ]);
}

it('RR.HH. aprueba o descarta una marcación señalada y queda registrado quién y cuándo', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    test()->actingAs($admin);

    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]);
    $approved = locationFlaggedEvent($employee);
    $discarded = locationFlaggedEvent($employee);

    Livewire::test(ManageAttendanceEvents::class)
        ->callTableAction('approve_location', $approved)
        ->callTableAction('discard_location', $discarded);

    expect($approved->fresh()->location_review)->toBe('approved')
        ->and($approved->fresh()->location_reviewed_by_id)->toBe($admin->id)
        ->and($approved->fresh()->location_reviewed_at)->not->toBeNull()
        ->and($discarded->fresh()->location_review)->toBe('discarded');
});

it('quien no puede editar marcaciones no ve las acciones de revisión de ubicación', function () {
    test()->actingAs(User::factory()->create());
    $event = locationFlaggedEvent(locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]));

    AttendanceEventResource::reviewLocation($event, 'approved');

    expect($event->fresh()->location_review)->toBe('pending');
});

it('la tarjeta de Por resolver cuenta las marcaciones fuera de radio pendientes', function () {
    test()->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = locationEmployee(employee: ['work_location' => ['lat' => LOC_LAT, 'lng' => LOC_LNG]]);
    locationFlaggedEvent($employee);
    locationFlaggedEvent($employee)->update(['location_review' => 'approved']);

    $card = collect(AttendanceInboxService::sections())->firstWhere('key', 'location_review');

    expect($card['count'])->toBe(1);
});

it('el radio por defecto se edita en Configuración General dentro de los límites', function () {
    test()->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['attendance_location_default_radius' => 10])
        ->call('save')
        ->assertHasFormErrors(['attendance_location_default_radius']);

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['attendance_location_default_radius' => 300])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->attendance_location_default_radius)->toBe(300);
});
