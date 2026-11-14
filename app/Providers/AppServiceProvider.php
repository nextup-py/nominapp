<?php

namespace App\Providers;

use App\Listeners\ReportMailFailure;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use App\Models\Warning;
use App\Observers\AttendanceDayObserver;
use App\Observers\AttendanceEventObserver;
use App\Observers\CompanyObserver;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Observers\TerminalObserver;
use App\Observers\WarningObserver;
use App\Services\MailConfigurator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El correo configurado en el panel (Configuración General → Correo) reemplaza al del `.env` recién cuando se usa el
        // envío, así las peticiones que no mandan correo no leen la base por esto.
        $this->app->resolving('mail.manager', fn () => MailConfigurator::applyRuntime());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AttendanceDay::observe(AttendanceDayObserver::class);
        AttendanceEvent::observe(AttendanceEventObserver::class);
        Company::observe(CompanyObserver::class);
        Contract::observe(ContractObserver::class);
        Employee::observe(EmployeeObserver::class);
        Terminal::observe(TerminalObserver::class);
        Warning::observe(WarningObserver::class);

        // Un correo que no sale se avisa al Super Admin por la campanita (antes solo quedaba en el log).
        Event::listen(NotificationFailed::class, ReportMailFailure::class);

        Gate::before(fn (User $user) => $user->hasRole('Super Admin') ? true : null);

        // Pantallas y descargas reservadas al Super Admin (configuración, logs): el Gate::before ya lo deja pasar.
        Gate::define('super-admin', fn (User $user) => $user->hasRole('Super Admin'));

        $this->configureRateLimiting();
    }

    /**
     * Rate limiters con nombre de la vinculación de terminales por código. Cada
     * limiter es independiente (la clave incluye el nombre), así que no comparten
     * bucket entre sí ni con otras rutas públicas — ver el gotcha de throttle
     * compartido en CLAUDE.md.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('terminal-pairing-create', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('terminal:'.strtolower((string) $request->input('terminal_code'))),
        ]);

        RateLimiter::for('terminal-pairing-poll', fn (Request $request) => [
            Limit::perMinute(40)->by('secret:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute(120)->by('ip:'.$request->ip()),
        ]);
    }
}
