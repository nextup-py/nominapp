<?php

use App\Filament\Resources\AttendanceDayResource\Pages\ViewAttendanceDay;
use App\Filament\Resources\AttendanceDayResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\AttendanceEventResource\Pages\ManageAttendanceEvents;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Crea un AttendanceEvent marcado "hoy" a las 21:32:39 — hora elegida a
 * propósito porque +3h de offset (America/Asuncion → UTC) cruza la
 * medianoche, ejerciendo el escenario exacto de la regresión. Se usa la
 * fecha real de "hoy" (no un valor fijo) porque ManageAttendanceEvents
 * arranca con el tab "Hoy" activo (whereDate('recorded_at', now())) — un
 * evento con fecha fija quedaría filtrado fuera de la tabla en CI y
 * mountTableAction() no lo encontraría.
 */
function makeEditableAttendanceEvent(): AttendanceEvent
{
    static $ci = 8700000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Edit {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Edit {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Edit {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Edit {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Edit', 'last_name' => 'Test', 'ci' => (string) $n,
        'birth_date' => '1990-01-01', 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    $recordedAt = Carbon::today()->setTime(21, 32, 39);
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $recordedAt->toDateString(), 'status' => 'present']);

    return AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'event_type' => 'break_start',
        'recorded_at' => $recordedAt,
        'source' => 'mobile',
    ]);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

/**
 * Regresión: $record->attributesToArray() (usado internamente por
 * EditAction::fillForm()) serializa `recorded_at` en UTC (Carbon::toJSON()),
 * no en la timezone de la app. Sin convertir antes de extraer fecha/hora, un
 * evento marcado a las 21:32 en Paraguay (UTC-3) prellenaba el modal con la
 * fecha del día SIGUIENTE (00:32 UTC cruza la medianoche).
 */
it('el modal de editar marcación prellena la fecha y hora correctas en la timezone de la app', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $event = makeEditableAttendanceEvent();
    $expectedDate = $event->recorded_at->toDateString();

    $test = Livewire::test(ManageAttendanceEvents::class)
        ->mountTableAction('edit', $event);

    $data = $test->instance()->mountedTableActionsData[array_key_last($test->instance()->mountedTableActionsData)];

    // '_date' es un campo Hidden plano, no pasa por la re-hidratación de
    // TimePicker, así que refleja exactamente lo calculado en
    // mutateRecordDataUsing(). 'time' sí la sufre (TimePicker le adjunta la
    // fecha de "hoy" a su estado interno, descartada al guardar — ver el
    // siguiente test para la verificación funcional real), por eso se
    // valida por contenido en vez de por igualdad exacta.
    expect($data['_date'])->toBe($expectedDate)
        ->and($data['time'])->toContain('21:32');
});

it('guardar el modal de editar marcación sin cambios no corre la fecha ni la hora', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $event = makeEditableAttendanceEvent();
    $expected = $event->recorded_at->format('Y-m-d H:i');

    Livewire::test(ManageAttendanceEvents::class)
        ->mountTableAction('edit', $event)
        ->callMountedTableAction();

    expect($event->fresh()->recorded_at->format('Y-m-d H:i'))->toBe($expected);
});

/**
 * Mismo bug, mismo patrón, en la relation manager de AttendanceDayResource.
 */
it('el modal de editar marcación en AttendanceDayResource prellena la fecha correcta', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $event = makeEditableAttendanceEvent();
    $expectedDate = $event->recorded_at->toDateString();

    $test = Livewire::test(
        EventsRelationManager::class,
        ['ownerRecord' => $event->day, 'pageClass' => ViewAttendanceDay::class]
    )
        ->mountTableAction('edit', $event);

    $data = $test->instance()->mountedTableActionsData[array_key_last($test->instance()->mountedTableActionsData)];

    expect($data['_date'])->toBe($expectedDate)
        ->and($data['time'])->toContain('21:32');
});
