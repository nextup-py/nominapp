<?php

namespace App\Providers;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Terminal;
use App\Observers\AttendanceDayObserver;
use App\Observers\AttendanceEventObserver;
use App\Observers\CompanyObserver;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Observers\TerminalObserver;
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
    }
}
