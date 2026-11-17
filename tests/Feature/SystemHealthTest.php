<?php

use App\Filament\Pages\SystemHealth;
use App\Models\User;
use App\Notifications\NewErrorNotification;
use App\Services\LogErrorAnalyzer;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Directorio temporal con un log de Laravel con las líneas dadas (`[fecha] local.NIVEL: mensaje`). */
function fakeLogDir(array $lines): string
{
    $dir = sys_get_temp_dir().'/logs_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/laravel-'.now()->format('Y-m-d').'.log', implode("\n", $lines)."\n");

    return $dir;
}

function logLine(string $level, string $message, ?string $at = null): string
{
    return '['.($at ?? now()->subMinutes(5)->format('Y-m-d H:i:s')).'] local.'.$level.': '.$message;
}

it('agrupa errores repetidos por firma, ignorando números y rutas', function () {
    $dir = fakeLogDir([
        logLine('ERROR', 'Employee 15 not found in /var/www/app/Foo.php'),
        logLine('ERROR', 'Employee 99 not found in /var/www/app/Bar.php'),
        logLine('INFO', 'Algo normal'),
        logLine('ERROR', 'Connection could not be established with host smtp.x.com'),
    ]);

    $groups = (new LogErrorAnalyzer)->groups(days: 1, directory: $dir);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['count'])->toBe(2)
        ->and($groups[1]['explanation']['what'])->toContain('correo');
});

it('explica los errores conocidos y deja null los desconocidos', function () {
    expect(LogErrorAnalyzer::explain('Allowed memory size of 134217728 bytes exhausted'))->not->toBeNull()
        ->and(LogErrorAnalyzer::explain('Algo rarísimo'))->toBeNull();
});

it('avisa solo de errores nuevos y la primera corrida no avisa', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $dir = fakeLogDir([logLine('ERROR', 'Error viejo uno')]);
    app()->bind(LogErrorAnalyzer::class, fn () => new class($dir) extends LogErrorAnalyzer
    {
        public function __construct(private string $dir) {}

        public function groups(int $days = 7, ?string $directory = null): Collection
        {
            return parent::groups($days, $this->dir);
        }
    });

    $this->artisan('logs:notify-new-errors')->assertSuccessful();
    Notification::assertNothingSent();

    file_put_contents($dir.'/laravel-'.now()->format('Y-m-d').'.log', logLine('ERROR', 'Error completamente distinto')."\n", FILE_APPEND);
    $this->artisan('logs:notify-new-errors')->assertSuccessful();
    Notification::assertSentTo($admin, NewErrorNotification::class);

    Notification::fake();
    $this->artisan('logs:notify-new-errors')->assertSuccessful();
    Notification::assertNothingSent();
});

it('el programador sin latido figura crítico y con latido reciente, bien', function () {
    Cache::forget(SystemHealthService::SCHEDULER_KEY);
    expect(app(SystemHealthService::class)->checks()['scheduler']['status'])->toBe('danger');

    Cache::forever(SystemHealthService::SCHEDULER_KEY, now()->subMinute());
    expect(app(SystemHealthService::class)->checks()['scheduler']['status'])->toBe('ok');

    Cache::forever(SystemHealthService::SCHEDULER_KEY, now()->subMinutes(20));
    expect(app(SystemHealthService::class)->checks()['scheduler']['status'])->toBe('danger');
});

it('la página es solo para Super Admin', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'RRHH', 'guard_name' => 'web']));
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    Livewire::actingAs($user)->test(SystemHealth::class)->assertForbidden();
    Livewire::actingAs($admin)->test(SystemHealth::class)->assertSuccessful()->assertSee('Programador');
});

it('rotate borra los logs diarios viejos y recorta los que crecen sin límite', function () {
    $dir = storage_path('logs');
    $old = $dir.'/laravel-2020-01-01.log';
    $cron = $dir.'/cron-test.log';
    file_put_contents($old, 'x');
    touch($old, now()->subDays(90)->timestamp);
    file_put_contents($cron, str_repeat("linea de log\n", 200000));

    $this->artisan('logs:rotate', ['--max-mb' => 1, '--days' => 30])->assertSuccessful();

    expect(file_exists($old))->toBeFalse()
        ->and(filesize($cron))->toBeLessThan(1024 * 1024);

    @unlink($cron);
});
