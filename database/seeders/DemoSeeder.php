<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder para entorno de demostración.
 *
 * Limpia la base de datos, crea el usuario administrador y siembra
 * todos los datos de ejemplo (empresa, sucursales, empleados, nómina, etc.).
 *
 * Es seguro correrlo múltiples veces: trunca las tablas antes de insertar.
 * NO usar en producción — contiene datos ficticios.
 *
 * Uso:
 *   php artisan db:seed --class=DemoSeeder
 *
 * Variables de entorno opcionales (mismas que ProductionSeeder):
 *   ADMIN_NAME     Nombre del usuario admin     (default: "Administrador")
 *   ADMIN_EMAIL    Email del usuario admin      (default: "admin@example.com")
 *   ADMIN_PASSWORD Contraseña del usuario admin (default: "password")
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->truncateTables();
        $this->createAdminUser();

        $this->call([
            PermissionSeeder::class,
            BusinessActionPermissionSeeder::class,
            RoleSeeder::class,

            // Configuración del sistema (debe ir primero: muchos servicios leen settings al iniciar)
            SettingsSeeder::class,

            // Catálogo geográfico (requerido por EmployeeAddressSeeder)
            ParaguayRegionsSeeder::class,

            // Estructura organizacional
            CompanySeeder::class,
            CompanyBankAccountSeeder::class,
            ScheduleSeeder::class,
            DepartmentSeeder::class,
            BranchSeeder::class,

            // Catálogos de nómina (antes de Employee: el observer asigna deducciones obligatorias)
            DeductionSeeder::class,
            PerceptionSeeder::class,

            // Empleados y asignaciones
            EmployeeSeeder::class,
            ScheduleAssignmentSeeder::class,
            ShiftTemplateSeeder::class,
            RotationPatternSeeder::class,
            EmployeePerceptionSeeder::class,
            DocumentSeeder::class,

            // Datos personales adicionales del empleado
            EmployeeChildSeeder::class,
            EmployeeAddressSeeder::class,

            // Asistencia y calendario
            HolidaySeeder::class,
            AttendanceDayWithEventsSeeder::class,

            // Vacaciones y permisos (antes de AbsenceSeeder: las ausencias
            // justificadas vinculan un EmployeeLeave aprobado, como exige
            // Absence::justify() en la app real)
            VacationSeeder::class,
            EmployeeLeaveSeeder::class,
            AbsenceSeeder::class,

            // Nómina y períodos
            PayrollPeriodSeeder::class,
            PayrollSeeder::class,
            LoanSeeder::class,
            AdvanceSeeder::class,
            MerchandiseWithdrawalSeeder::class,

            // Documentación laboral
            WarningSeeder::class,

            // Plantillas de contratos
            ContractTemplateSeeder::class,

            // Procesos anuales e históricos
            AguinaldoSeeder::class,
            LiquidacionSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->line('<fg=green>✔</> Demo lista.</fg=green>');
        $this->command->newLine();
    }

    /** Crea el usuario administrador usando las mismas variables de entorno que ProductionSeeder. */
    private function createAdminUser(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name = env('ADMIN_NAME', 'Administrador');
        $password = env('ADMIN_PASSWORD') ?: 'password';

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
            ]
        );

        $this->command->info("Usuario admin: $email / $password");
    }

    /**
     * Limpia todas las tablas de la aplicación antes de seedear.
     * Con FK checks desactivados, el orden no importa.
     */
    private function truncateTables(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            // Nómina y procesos anuales
            'aguinaldo_items',
            'aguinaldos',
            'aguinaldo_periods',
            'liquidacion_items',
            'liquidaciones',
            // Pagos bancarios
            'disbursement_batches',
            // Adelantos y préstamos
            'advances',
            'loan_installments',
            'loans',
            // Retiros de mercadería
            'merchandise_withdrawal_installments',
            'merchandise_withdrawal_items',
            'merchandise_withdrawals',
            // Documentación laboral
            'warnings',
            'documents',
            // Vacaciones y ausencias
            'employee_vacation_balances',
            'vacations',
            'employee_leaves',
            'absences',
            // Asistencia
            'attendance_mark_failures',
            'attendance_events',
            'attendance_days',
            // Nómina base
            'payroll_items',
            'payrolls',
            'payroll_periods',
            // Reconocimiento facial
            'face_enrollments',
            // Percepciones y deducciones de empleados
            'employee_deductions',
            'employee_perceptions',
            // Turnos y rotaciones
            'shift_overrides',
            'rotation_assignments',
            'rotation_patterns',
            'shift_templates',
            // Horarios y asignaciones
            'employee_schedule_assignments',
            // Datos personales del empleado
            'employee_addresses',
            'employee_children',
            'employee_bank_accounts',
            // Contratos y plantillas
            'contract_templates',
            'contracts',
            // Empleados
            'employees',
            // Horarios
            'schedule_breaks',
            'schedule_days',
            'schedules',
            // Estructura organizacional
            'positions',
            'departments',
            'branches',
            'company_bank_accounts',
            'companies',
            // Catálogos de nómina
            'deductions',
            'perceptions',
            // Catálogos geográficos
            'py_cities',
            'py_departments',
            // Otros
            'holidays',
            'terminals',
            'settings',
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
