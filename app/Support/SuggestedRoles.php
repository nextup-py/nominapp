<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles sugeridos que se pueden sumar a cualquier instalación sin tocar los que ya existen: se crean solo si faltan
 * (con su descripción y permisos) y nunca se pisa un rol que ya tiene ese nombre.
 */
class SuggestedRoles
{
    /**
     * Catálogo: nombre => descripción y permisos.
     *
     * @return array<string, array{description: string, permissions: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            'Jefe de equipo' => [
                'description' => 'Ve en «Mi equipo» el estado de hoy de las personas que le reportan, directa o indirectamente (solo consulta). No accede al resto del panel.',
                'permissions' => ['view_team'],
            ],
            'Supervisor de sucursal' => [
                'description' => 'Igual que Jefe de equipo, y además ve a todo el personal de su sucursal en «Mi equipo». No accede al resto del panel.',
                'permissions' => ['view_team', 'view_branch_team'],
            ],
            'Soporte TI' => [
                'description' => 'Gestiona terminales y su vinculación, dispositivos de empleados, enrolamiento facial y fallos de marcación, y ve Salud del sistema y logs. Ve solo el listado de empleados: no accede a contratos, salarios ni nómina, ni a la configuración.',
                'permissions' => [
                    'view_any_terminal', 'view_terminal', 'create_terminal', 'update_terminal',
                    'view_any_branch', 'view_branch',
                    'view_any_employee_device', 'view_employee_device', 'update_employee_device',
                    'view_any_face_enrollment', 'view_face_enrollment', 'update_face_enrollment',
                    'view_any_attendance_mark_failure', 'view_attendance_mark_failure', 'update_attendance_mark_failure',
                    'view_any_attendance_event', 'view_attendance_event',
                    'view_any_employee',
                    'view_system_health',
                ],
            ],
            'Encargado de asistencia' => [
                'description' => 'Aprueba tardanzas y horas extra, corrige marcaciones y resuelve ausencias de toda la empresa. No ve nómina ni salarios.',
                'permissions' => [
                    'view_any_attendance_day', 'view_attendance_day', 'update_attendance_day',
                    'view_any_attendance_event', 'view_attendance_event', 'create_attendance_event', 'update_attendance_event', 'delete_attendance_event',
                    'view_any_attendance_mark_failure', 'view_attendance_mark_failure', 'update_attendance_mark_failure',
                    'view_any_absence', 'view_absence', 'update_absence',
                    'justify_absence', 'mark_unjustified_absence', 'export_absence',
                    'view_any_schedule', 'view_schedule',
                ],
            ],
        ];
    }

    /**
     * Crea los roles sugeridos que todavía no existen y devuelve sus nombres.
     *
     * @return array<int, string>
     */
    public static function createMissing(): array
    {
        $created = [];

        foreach (self::all() as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if (! $role->wasRecentlyCreated) {
                continue;
            }

            $role->update(['description' => $definition['description']]);
            $role->syncPermissions(
                collect($definition['permissions'])
                    ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                    ->all()
            );
            $created[] = $name;
        }

        return $created;
    }
}
