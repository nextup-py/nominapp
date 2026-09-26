<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeder para nueva instalación de cliente.
 *
 * Solo siembra datos reales/obligatorios, nunca datos demo o específicos
 * de un cliente en particular:
 *   - Usuario administrador (configurable vía .env)
 *   - Deducciones del sistema requeridas por los calculators de nómina (IPS, préstamos, adelantos, mercadería, permisos)
 *   - Catálogo geográfico oficial de Paraguay (departamentos y ciudades)
 *   - Feriados nacionales del año en curso
 *   - Plantilla vacía de contrato por cada tipo (para que ContractResource tenga un registro base)
 *
 * Es idempotente: se puede correr múltiples veces sin duplicar registros.
 * NO trunca tablas ni inserta datos demo.
 *
 * Uso:
 *   php artisan db:seed --class=ProductionSeeder
 *
 * Variables de entorno opcionales:
 *   ADMIN_NAME     Nombre del usuario admin     (default: "Administrador")
 *   ADMIN_EMAIL    Email del usuario admin      (default: "admin@example.com")
 *   ADMIN_PASSWORD Contraseña del usuario admin (default: generada aleatoriamente)
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->createAdminUser();
        $this->call([
            PermissionSeeder::class,
            BusinessActionPermissionSeeder::class,
            RoleSeeder::class,
        ]);
        $this->seedDeductions();

        $this->call([
            ParaguayRegionsSeeder::class,
            HolidaySeeder::class,
            ContractTemplateSeeder::class,
        ]);

        $this->printChecklist();
    }

    private function createAdminUser(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name = env('ADMIN_NAME', 'Administrador');
        $password = env('ADMIN_PASSWORD') ?: Str::random(12);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt($password),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->command->newLine();
            $this->command->info('Usuario administrador creado:');
            $this->command->line("  Nombre:     $name");
            $this->command->line("  Email:      $email");
            $this->command->line("  Contraseña: $password");
            $this->command->warn('  ¡Cambia esta contraseña inmediatamente tras el primer acceso!');
            $this->command->newLine();
        } else {
            $this->command->info("Usuario admin ya existe: $email (sin cambios).");
        }
    }

    private function seedDeductions(): void
    {
        $now = now();

        $deductions = [
            // ── Deducciones legales obligatorias ──────────────────────────────
            [
                'name' => 'Aporte IPS',
                'code' => 'IPS001',
                'type' => 'legal',
                'description' => 'Aporte al Instituto de Previsión Social (9% del salario)',
                'calculation' => 'percentage',
                'amount' => null,
                'percent' => 9.00,
                'is_mandatory' => true,
                'affects_irp' => true,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],

            // ── Deducciones del sistema — Préstamos y Adelantos ───────────────
            // Usadas internamente por LoanInstallmentCalculator.
            // El monto real viene del custom_amount del EmployeeDeduction (cuota específica).
            [
                'name' => 'Cuota de Préstamo',
                'code' => 'PRE001',
                'type' => 'loan',
                'description' => 'Descuento de cuota de préstamo otorgado por el empleador. El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cuota de Adelanto de Salario',
                'code' => 'ADE001',
                'type' => 'loan',
                'description' => 'Descuento de adelanto de salario (hasta 50% del salario del período). El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Cuota de Retiro de Mercadería',
                'code' => 'MER001',
                'type' => 'loan',
                'description' => 'Descuento de cuota por retiro de mercadería a crédito. El monto se fija por cuota individual.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Descuento por Permiso Parcial',
                'code' => 'LIC001',
                'type' => 'voluntary',
                'description' => 'Descuento por horas de permiso parcial aprobado. El monto se calcula automáticamente según el salario y las horas tomadas.',
                'calculation' => 'fixed',
                'amount' => null,
                'percent' => null,
                'is_mandatory' => false,
                'affects_irp' => false,
                'apply_judicial_limit' => false,
                'is_active' => true,
            ],
        ];

        foreach ($deductions as $deduction) {
            DB::table('deductions')->updateOrInsert(
                ['code' => $deduction['code']],
                array_merge($deduction, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        $this->command->info('Deducciones sembradas: IPS (9%), PRE001, ADE001, MER001, LIC001.');
    }

    private function printChecklist(): void
    {
        $this->command->newLine();
        $this->command->line('<fg=green>✔</> Instalación base completada.</fg=green>');
        $this->command->newLine();
        $this->command->warn('Pasos pendientes para dejar el sistema operativo:');
        $this->command->newLine();
        $this->command->line('  [ ] Al primer login del administrador, el panel activa automáticamente');
        $this->command->line('        el asistente de configuración inicial (empresa, sucursales,');
        $this->command->line('        estructura organizacional, módulos opcionales y parámetros clave');
        $this->command->line('        de nómina) — no requiere pasos manuales previos, pero horarios de');
        $this->command->line('        trabajo y catálogos avanzados quedan fuera del asistente.');
        $this->command->line('');
        $this->command->line('  [ ] Horarios de trabajo (Asistencias → Horarios): crear al menos uno.');
        $this->command->line('');
        $this->command->line('  [ ] Feriados nacionales (Panel → Feriados):');
        $this->command->line('        - Ya se cargaron los feriados oficiales del año en curso — revisar/ajustar si el cliente tiene acuerdos locales distintos');
        $this->command->line('        - Repetir al inicio de cada año calendario (volver a correr ProductionSeeder o cargar manualmente)');
        $this->command->line('');
        $this->command->line('  [ ] Catálogos opcionales:');
        $this->command->line('        - Agregar deducciones adicionales (seguros, sindicato, etc.)');
        $this->command->line('        - Agregar tipos de percepciones/bonificaciones');
        $this->command->line('');
        $this->command->line('  [ ] Cambiar la contraseña del administrador tras el primer acceso.');
        $this->command->newLine();
    }
}
