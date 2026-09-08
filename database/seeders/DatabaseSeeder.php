<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->truncateTables();

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $this->call([
            // Estructura organizacional
            CompanySeeder::class,
            ScheduleSeeder::class,
            DepartmentSeeder::class,
            BranchSeeder::class,

            // Catálogos de nómina (antes de Employee: el observer asigna deducciones obligatorias)
            DeductionSeeder::class,
            PerceptionSeeder::class,

            // Empleados y asignaciones
            EmployeeSeeder::class,
            EmployeePerceptionSeeder::class,
            DocumentSeeder::class,

            // Asistencia y calendario
            HolidaySeeder::class,
            AttendanceDayWithEventsSeeder::class,

            // Vacaciones y permisos
            VacationSeeder::class,
            EmployeeLeaveSeeder::class,

            // Nómina y períodos
            PayrollPeriodSeeder::class,
            LoanSeeder::class,
            AdvanceSeeder::class,

            // Procesos anuales e históricos
            AguinaldoSeeder::class,
            LiquidacionSeeder::class,
        ]);
    }

    /**
     * Limpia todas las tablas de la aplicación antes de seedear.
     * Con FK checks desactivados, el orden no importa.
     */
    private function truncateTables(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'aguinaldo_items',
            'aguinaldos',
            'aguinaldo_periods',
            'liquidacion_items',
            'liquidaciones',
            'advances',
            'loan_installments',
            'loans',
            'documents',
            'employee_vacation_balances',
            'vacations',
            'employee_leaves',
            'absences',
            'attendance_events',
            'attendance_days',
            'payroll_items',
            'payrolls',
            'payroll_periods',
            'employee_deductions',
            'employee_perceptions',
            'employee_schedule_assignments',
            'employee_bank_accounts',
            'contracts',
            'employees',
            'schedule_breaks',
            'schedule_days',
            'schedules',
            'positions',
            'departments',
            'branches',
            'companies',
            'deductions',
            'perceptions',
            'holidays',
            'users',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}
