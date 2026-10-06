<?php

namespace App\Providers;

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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
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

        Gate::before(fn (User $user) => $user->hasRole('Super Admin') ? true : null);

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
