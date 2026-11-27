<?php

use App\Filament\Pages\OvertimeSettings;
use App\Models\AttendanceEvent;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    $general = app(GeneralSettings::class);
    $general->setup_completed = true;
    $general->save();
});

/** Usuario con el rol indicado. */
function overtimeSettingsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

    return $user;
}

/** Estado base válido del formulario, tomado de los ajustes actuales. */
function overtimeFormState(array $override = []): array
{
    $payroll = app(PayrollSettings::class);
    $general = app(GeneralSettings::class);

    return array_merge([
        'overtime_multiplier_diurno' => $payroll->overtime_multiplier_diurno,
        'overtime_multiplier_nocturno' => $payroll->overtime_multiplier_nocturno,
        'overtime_multiplier_holiday' => $payroll->overtime_multiplier_holiday,
        'overtime_multiplier_nocturno_holiday' => $payroll->overtime_multiplier_nocturno_holiday,
        'overtime_max_daily_hours' => $payroll->overtime_max_daily_hours,
        'overtime_max_weekly_hours' => $payroll->overtime_max_weekly_hours,
        'attendance_overtime_tolerance_minutes' => $general->attendance_overtime_tolerance_minutes,
        'attendance_overtime_allowed_default' => $general->attendance_overtime_allowed_default,
        'attendance_overtime_request_required_default' => false,
        'attendance_overtime_request_min_notice_hours' => 0,
        'attendance_overtime_reason_required' => false,
        'attendance_overtime_reason_timeout_seconds' => 20,
        'attendance_overtime_reasons' => array_values($general->attendance_overtime_reasons),
    ], $override);
}

it('solo el Super Admin ve la página de horas extra', function () {
    $this->actingAs(overtimeSettingsUser('RRHH'));
    expect(OvertimeSettings::canAccess())->toBeFalse();

    $this->actingAs(overtimeSettingsUser('Super Admin'));
    expect(OvertimeSettings::canAccess())->toBeTrue();
});

it('guarda multiplicadores, límites y tolerancia en sus ajustes de origen', function () {
    $this->actingAs(overtimeSettingsUser('Super Admin'));

    Livewire::test(OvertimeSettings::class)
        ->fillForm(overtimeFormState([
            'overtime_multiplier_diurno' => 1.6,
            'overtime_max_daily_hours' => 4,
            'attendance_overtime_tolerance_minutes' => 10,
            'attendance_overtime_allowed_default' => false,
            'attendance_overtime_reason_required' => true,
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(PayrollSettings::class)->overtime_multiplier_diurno)->toBe(1.6)
        ->and(app(PayrollSettings::class)->overtime_max_daily_hours)->toBe(4)
        ->and(app(GeneralSettings::class)->attendance_overtime_tolerance_minutes)->toBe(10)
        ->and(app(GeneralSettings::class)->attendance_overtime_allowed_default)->toBeFalse()
        ->and(app(GeneralSettings::class)->attendance_overtime_reason_required)->toBeTrue();
});

it('un motivo nuevo recibe una clave estable y uno desactivado deja de ofrecerse pero se sigue mostrando', function () {
    $this->actingAs(overtimeSettingsUser('Super Admin'));

    $reasons = array_values(app(GeneralSettings::class)->attendance_overtime_reasons);
    $reasons[0]['active'] = false; // cash_close
    $reasons[] = ['key' => null, 'label' => 'Inventario anual', 'active' => true];

    Livewire::test(OvertimeSettings::class)
        ->fillForm(overtimeFormState(['attendance_overtime_reasons' => $reasons]))
        ->call('save');

    expect(AttendanceEvent::getOvertimeReasonOptions())
        ->not->toHaveKey('cash_close')
        ->toHaveKey('inventario_anual', 'Inventario anual')
        ->and(AttendanceEvent::overtimeReasonLabel('cash_close'))->toBe('Cierre de caja')
        ->and(AttendanceEvent::sanitizeOvertimeReason('cash_close'))->toBe('cash_close')
        ->and(AttendanceEvent::sanitizeOvertimeReason('inventado'))->toBeNull();
});

it('rechaza un catálogo sin motivos activos o con nombres repetidos', function (array $reasons) {
    $this->actingAs(overtimeSettingsUser('Super Admin'));
    $before = app(GeneralSettings::class)->attendance_overtime_reasons;

    Livewire::test(OvertimeSettings::class)
        ->fillForm(overtimeFormState(['attendance_overtime_reasons' => $reasons]))
        ->call('save');

    expect(app(GeneralSettings::class)->attendance_overtime_reasons)->toBe($before);
})->with([
    'sin activos' => [[['key' => 'a', 'label' => 'Uno', 'active' => false]]],
    'repetidos' => [[['key' => 'a', 'label' => 'Uno', 'active' => true], ['key' => 'b', 'label' => 'uno', 'active' => true]]],
]);

it('normalizeReasons evita colisiones de clave entre motivos nuevos con el mismo nombre base', function () {
    $result = OvertimeSettings::normalizeReasons([
        ['key' => 'cierre', 'label' => 'Cierre', 'active' => true],
        ['key' => null, 'label' => 'Cierre!', 'active' => true],
    ]);

    expect(collect($result)->pluck('key')->all())->toBe(['cierre', 'cierre_2']);
});
