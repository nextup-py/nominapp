<?php

use App\Console\Commands\AutoCloseOpenShifts;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\NoExitTodayNotification;
use App\Notifications\OpenShiftsAutoClosedNotification;
use App\Settings\GeneralSettings;
use App\Support\ConfiguredTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 22:00:00'));
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

function autoEmployee(string $first): Employee
{
    static $ci = 4800100;
    $company = Company::first() ?? Company::create(['name' => 'Empresa Auto', 'ruc' => '4800000-1', 'employer_number' => 4800000]);
    $branch = Branch::firstOrCreate(['name' => 'Central', 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => $first, 'last_name' => 'Auto', 'ci' => (string) $ci++, 'email' => strtolower($first).'@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

/** @param  array<int, array{0: string, 1: string}>  $events */
function autoDay(Employee $employee, string $date, array $events): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'present',
        'expected_check_in' => '08:00:00', 'expected_check_out' => '18:00:00', 'expected_hours' => 10.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'employee_name' => $employee->full_name,
            'event_type' => $type, 'recorded_at' => Carbon::parse("{$date} {$time}"), 'source' => 'terminal',
        ]);
    }

    return $day;
}

function autoManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']));

    return $user;
}

it('el cierre automático está apagado por defecto y no toca nada', function () {
    $day = autoDay(autoEmployee('Olvido'), '2026-11-14', [['check_in', '08:05']]);

    $this->artisan('attendance:auto-close-open-shifts')->expectsOutputToContain('apagado')->assertSuccessful();

    expect($day->fresh()->events()->count())->toBe(1);
});

it('cierra con la mejor regla disponible y avisa a RR.HH. con el resumen', function () {
    Notification::fake();
    app(GeneralSettings::class)->attendance_auto_close_enabled = true;

    $employee = autoEmployee('Olvido');
    $plain = autoDay($employee, '2026-11-14', [['check_in', '08:05']]);
    $breakAsExit = autoDay($employee, '2026-11-13', [['check_in', '08:05'], ['break_start', '17:58']]);
    $lastMark = autoDay($employee, '2026-11-12', [['check_in', '08:05'], ['break_start', '12:00'], ['break_end', '18:20']]);
    $review = autoDay($employee, '2026-11-11', [['check_in', '08:05'], ['break_start', '12:00']]);
    $tooOld = autoDay($employee, '2026-11-01', [['check_in', '08:05']]);
    $today = autoDay($employee, '2026-11-16', [['check_in', '08:05']]);
    $manager = autoManager();

    $this->artisan('attendance:auto-close-open-shifts')
        ->expectsOutputToContain('Cerradas 3 jornada(s); 1 quedan para revisar')
        ->assertSuccessful();

    $lastType = fn (AttendanceDay $day) => $day->fresh()->events()->orderBy('recorded_at')->orderBy('id')->get()->last();

    expect($lastType($plain)->event_type)->toBe('check_out')
        ->and($lastType($plain)->recorded_at->format('H:i'))->toBe('18:00')
        ->and($lastType($breakAsExit)->event_type)->toBe('check_out')
        ->and($lastType($breakAsExit)->recorded_at->format('H:i'))->toBe('17:58')
        ->and($lastType($lastMark)->event_type)->toBe('check_out')
        ->and($lastType($lastMark)->recorded_at->format('H:i'))->toBe('18:20')
        ->and($lastType($review)->event_type)->toBe('break_start')
        ->and($tooOld->fresh()->events()->count())->toBe(1)
        ->and($today->fresh()->events()->count())->toBe(1);

    Notification::assertSentTo($manager, OpenShiftsAutoClosedNotification::class, fn ($n) => $n->closed === 3 && $n->review === 1);
});

it('con --dry-run cuenta sin guardar ni avisar', function () {
    Notification::fake();
    $day = autoDay(autoEmployee('Olvido'), '2026-11-14', [['check_in', '08:05']]);
    autoManager();

    $this->artisan('attendance:auto-close-open-shifts', ['--force' => true, '--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Se cerrarían 1 jornada(s)')
        ->assertSuccessful();

    expect($day->fresh()->events()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('el aviso de la noche lista a quienes se fueron sin marcar y no se repite mientras siga sin leer', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 21:30:00'));
    $today = '2026-11-16';

    $olvido = autoEmployee('Olvido');
    Cache::put("live-monitor:shift:{$olvido->id}:{$today}", ['check_in' => '08:00:00', 'check_out' => '18:00:00', 'break_minutes' => 30], 300);
    autoDay($olvido, $today, [['check_in', '08:05']]);

    $salio = autoEmployee('Salio');
    Cache::put("live-monitor:shift:{$salio->id}:{$today}", ['check_in' => '08:00:00', 'check_out' => '18:00:00', 'break_minutes' => 30], 300);
    autoDay($salio, $today, [['check_in', '08:00'], ['check_out', '18:01']]);

    $manager = autoManager();

    $this->artisan('attendance:notify-no-exit-today')->expectsOutputToContain('1 empleado(s) sin salida hoy; aviso enviado a 1')->assertSuccessful();

    $notification = $manager->fresh()->unreadNotifications()->where('type', NoExitTodayNotification::class)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['body'])->toContain('Olvido Auto')->not->toContain('Salio Auto');

    $this->artisan('attendance:notify-no-exit-today')->expectsOutputToContain('aviso enviado a 0')->assertSuccessful();
});

it('el aviso de la noche respeta el interruptor y el cierre automático, la hora configurada', function () {
    $settings = app(GeneralSettings::class);

    $settings->attendance_no_exit_notice_enabled = false;
    $this->artisan('attendance:notify-no-exit-today')->expectsOutputToContain('apagado')->assertSuccessful();

    $settings->attendance_auto_close_enabled = true;
    $settings->attendance_auto_close_time = '22:00';
    expect(ConfiguredTime::isDue('attendance_auto_close_enabled', 'attendance_auto_close_time'))->toBeTrue();

    $settings->attendance_auto_close_time = '05:30';
    expect(ConfiguredTime::isDue('attendance_auto_close_enabled', 'attendance_auto_close_time'))->toBeFalse();

    $settings->attendance_auto_close_enabled = false;
    $settings->attendance_auto_close_time = '22:00';
    expect(ConfiguredTime::isDue('attendance_auto_close_enabled', 'attendance_auto_close_time'))->toBeFalse()
        ->and(AutoCloseOpenShifts::LOOKBACK_DAYS)->toBe(7);
});
