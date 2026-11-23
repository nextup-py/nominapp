<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Crea un usuario por rol para mostrar en la demo qué ve y qué puede hacer cada perfil (permisos). Todos comparten
 * la contraseña `DEMO_USERS_PASSWORD` (variable de entorno; por defecto `DemoNominapp2026`). Se corre después de
 * `RoleSeeder` (que crea los roles, incluidos los sugeridos) y de los empleados (el jefe se vincula a su ficha).
 */
class DemoUsersSeeder extends Seeder
{
    /** Contraseña por defecto de los usuarios de la demo (cumple la política del panel: 10+ caracteres, mayúsculas, minúsculas y números). */
    public const DEFAULT_PASSWORD = 'DemoNominapp2026';

    /**
     * Usuarios de la demo: correo => [nombre, rol].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const USERS = [
        'rrhh@demo.nextup.com.py' => ['Recursos Humanos (demo)', 'RRHH'],
        'nomina@demo.nextup.com.py' => ['Contador/a de nómina (demo)', 'Contador/Nómina'],
        'jefe@demo.nextup.com.py' => ['Jefe/a de equipo (demo)', 'Jefe de equipo'],
        'soporte@demo.nextup.com.py' => ['Soporte TI (demo)', 'Soporte TI'],
        'lectura@demo.nextup.com.py' => ['Solo lectura (demo)', 'Solo Lectura'],
    ];

    public function run(): void
    {
        $password = env('DEMO_USERS_PASSWORD') ?: self::DEFAULT_PASSWORD;
        $managerId = $this->teamManagerId();

        foreach (self::USERS as $email => [$name, $roleName]) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                $this->command?->warn("Falta el rol «{$roleName}»: se omite {$email}.");

                continue;
            }

            $user = User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'is_active' => true,
                'employee_id' => $roleName === 'Jefe de equipo' ? $managerId : null,
            ]);
            $user->syncRoles([$role]);

            $this->command?->line("  {$roleName}: {$email}");
        }

        $this->command?->info("Contraseña de los usuarios de la demo: {$password}");
    }

    /** Empleado con más personas a cargo, para que «Mi equipo» del jefe muestre un equipo real. */
    private function teamManagerId(): ?int
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereHas('subordinates')
            ->withCount('subordinates')
            ->orderByDesc('subordinates_count')
            ->value('id');
    }
}
