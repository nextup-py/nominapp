<?php

use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceEventCorrectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Jornada reciente con entrada 07:00 y salida 15:00, sin cálculo previo. */
function makeFixableDay(): AttendanceDay
{
    $company = Company::create(['name' => 'Empresa FX', 'ruc' => '4500000-1', 'employer_number' => 4500000]);
    $branch = Branch::create(['name' => 'Sucursal FX', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Corrige', 'last_name' => 'Marca', 'ci' => '4500001', 'email' => 'fx@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    $date = Carbon::today()->subDays(3);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'absent',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ([['check_in', '07:00:00'], ['check_out', '15:00:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse($date->toDateString().' '.$time),
        ]);
    }

    return $day->fresh();
}

function fixRows(AttendanceDay $day, array $overrides = []): array
{
    $rows = $day->events()->orderBy('recorded_at')->get()->map(fn ($e) => [
        'id' => $e->id, 'event_type' => $e->event_type, 'recorded_at' => $e->recorded_at->format('Y-m-d H:i:s'),
    ])->all();

    return array_replace($rows, $overrides);
}

it('agrega la salida que faltaba y recalcula la jornada', function () {
    $day = makeFixableDay();
    $day->events()->where('event_type', 'check_out')->delete();

    $rows = fixRows($day);
    $rows[] = ['id' => null, 'event_type' => 'check_out', 'recorded_at' => $day->date->format('Y-m-d').' 15:10:00'];

    $summary = app(AttendanceEventCorrectionService::class)->apply($day, $rows);

    expect($summary)->toBe(['created' => 1, 'updated' => 0, 'deleted' => 0])
        ->and($day->fresh()->check_out_time)->toStartWith('15:10')
        ->and($day->events()->where('event_type', 'check_out')->value('source'))->toBe('manual');
});

it('cambia la hora de una marcación y la marca como manual', function () {
    $day = makeFixableDay();
    $rows = fixRows($day);
    $rows[1]['recorded_at'] = $day->date->format('Y-m-d').' 16:00:00';

    $summary = app(AttendanceEventCorrectionService::class)->apply($day, $rows);

    $out = $day->events()->where('event_type', 'check_out')->first();
    expect($summary['updated'])->toBe(1)
        ->and($out->recorded_at->format('H:i'))->toBe('16:00')
        ->and($out->source)->toBe('manual');
});

it('elimina una marcación quitada del formulario', function () {
    $day = makeFixableDay();
    $rows = fixRows($day);
    array_pop($rows);

    $summary = app(AttendanceEventCorrectionService::class)->apply($day, $rows);

    expect($summary['deleted'])->toBe(1)
        ->and($day->events()->count())->toBe(1);
});

it('admite una salida de madrugada con fecha del día siguiente', function () {
    $day = makeFixableDay();
    $rows = fixRows($day);
    $rows[1]['recorded_at'] = $day->date->copy()->addDay()->format('Y-m-d').' 03:00:00';

    app(AttendanceEventCorrectionService::class)->apply($day, $rows);

    expect($day->events()->where('event_type', 'check_out')->first()->recorded_at->format('d H:i'))
        ->toBe($day->date->copy()->addDay()->format('d').' 03:00');
});

it('rechaza una secuencia inválida sin tocar nada', function () {
    $day = makeFixableDay();
    $rows = fixRows($day);
    $rows[1]['event_type'] = 'check_in';

    expect(fn () => app(AttendanceEventCorrectionService::class)->apply($day, $rows))
        ->toThrow(InvalidArgumentException::class);

    expect($day->events()->pluck('event_type')->all())->toBe(['check_in', 'check_out']);
});

it('rechaza marcaciones futuras o fuera de la jornada', function () {
    $day = makeFixableDay();
    $service = app(AttendanceEventCorrectionService::class);

    $future = fixRows($day);
    $future[1]['recorded_at'] = now()->addDay()->format('Y-m-d H:i:s');
    $outside = fixRows($day);
    $outside[1]['recorded_at'] = $day->date->copy()->addDays(3)->format('Y-m-d').' 10:00:00';

    expect(fn () => $service->apply($day, $future))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->apply($day, $outside))->toThrow(InvalidArgumentException::class);
});

it('corrige desde el modal del listado', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $day = makeFixableDay();
    $rows = fixRows($day);
    $rows[1]['recorded_at'] = $day->date->format('Y-m-d').' 14:30:00';

    $component = Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->mountTableAction('fix_events', $day);

    $keys = array_keys($component->get('mountedTableActionsData.0.events'));
    $component
        ->setTableActionData(['events' => [$keys[0] => $rows[0], $keys[1] => $rows[1]]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($day->events()->where('event_type', 'check_out')->first()->recorded_at->format('H:i'))->toBe('14:30');
});

it('oculta la acción a quien no puede editar marcaciones', function () {
    Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']);
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view_any_attendance_day')));
    $day = makeFixableDay();

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->assertTableActionHidden('fix_events', $day);
});
