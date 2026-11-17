<?php

use App\Filament\Resources\HolidayResource\Pages\ManageHolidays;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Notifications\HolidaysMissingNotification;
use App\Services\HolidayWorkforce;
use App\Services\VacationService;
use App\Support\HolidayCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function holidayAdmin(): User
{
    return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
}

it('carga los feriados fijos de un año sin duplicar los existentes', function () {
    Holiday::create(['date' => '2027-01-01', 'name' => 'Año Nuevo', 'type' => 'national_fixed']);

    $created = HolidayCatalog::load(2027);

    expect($created)->toBe(count(HolidayCatalog::fixed()) - 1)
        ->and(Holiday::whereYear('date', 2027)->count())->toBe(count(HolidayCatalog::fixed()))
        ->and(HolidayCatalog::load(2027))->toBe(0);
});

it('copiar al próximo año solo copia los fijos y avisa de los móviles', function () {
    Holiday::create(['date' => '2026-05-01', 'name' => 'Día del Trabajador', 'type' => 'national_fixed']);
    Holiday::create(['date' => '2026-04-02', 'name' => 'Jueves Santo', 'type' => 'national_moving']);
    Holiday::create(['date' => '2026-11-02', 'name' => 'Puente', 'type' => 'non_working']);

    $result = HolidayCatalog::copyFixed(2026);

    expect($result['created'])->toBe(1)
        ->and(Holiday::whereYear('date', 2027)->pluck('name')->all())->toBe(['Día del Trabajador'])
        ->and($result['skipped_moving'])->toBe(['Jueves Santo', 'Puente']);
});

it('las acciones de la página cargan y copian feriados', function () {
    Livewire::actingAs(holidayAdmin())->test(ManageHolidays::class)
        ->callAction('import_default', ['year' => 2028])
        ->assertHasNoActionErrors();

    expect(Holiday::whereYear('date', 2028)->count())->toBe(count(HolidayCatalog::fixed()));
});

it('el calendario se abre sin errores', function () {
    Holiday::create(['date' => now()->year.'-05-01', 'name' => 'Día del Trabajador', 'type' => 'national_fixed']);

    Livewire::actingAs(holidayAdmin())->test(ManageHolidays::class)
        ->mountAction('calendar')
        ->assertSee('Calendario de feriados');
});

it('avisa a RR.HH. si faltan los feriados, una sola vez mientras no se lea', function () {
    Notification::fake();
    $admin = holidayAdmin();
    Holiday::query()->delete();

    $this->artisan('holidays:notify-missing')->assertSuccessful();
    Notification::assertSentTo($admin, HolidaysMissingNotification::class);
});

it('no avisa si los feriados del año están cargados', function () {
    Notification::fake();
    holidayAdmin();
    HolidayCatalog::load(now()->year);
    if (now()->month >= 11) {
        HolidayCatalog::load(now()->year + 1);
    }

    $this->artisan('holidays:notify-missing')->assertSuccessful();
    Notification::assertNothingSent();
});

it('el formulario de vacaciones advierte si el año no tiene feriados cargados', function () {
    static $n = 4200000;
    $company = Company::create(['name' => 'EmpHF', 'ruc' => '4200001-1', 'employer_number' => 4200001]);
    $branch = Branch::create(['name' => 'SucHF', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => 'Hol', 'ci' => '4200001', 'branch_id' => $branch->id, 'status' => 'active']);
    Holiday::query()->delete();

    $result = VacationService::validateRequest($employee, Carbon::parse('2031-06-01'), Carbon::parse('2031-06-06'));

    expect(collect($result['warnings'])->contains(fn (string $w): bool => str_contains($w, 'No hay feriados cargados para 2031')))->toBeTrue();
});

it('quién trabaja: marcaciones en fechas pasadas', function () {
    $company = Company::create(['name' => 'EmpHW', 'ruc' => '4300001-1', 'employer_number' => 4300001]);
    $branch = Branch::create(['name' => 'SucHW', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => 'Trab', 'ci' => '4300001', 'branch_id' => $branch->id, 'status' => 'active']);
    AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-09-29', 'status' => 'present', 'check_in_time' => '08:00:00', 'total_hours' => 9.5]);

    $result = HolidayWorkforce::forDate(Carbon::parse('2026-09-29'));

    expect($result['mode'])->toBe('worked')
        ->and($result['employees'])->toHaveCount(1)
        ->and($result['employees'][0]['branch'])->toBe('SucHW');
});
