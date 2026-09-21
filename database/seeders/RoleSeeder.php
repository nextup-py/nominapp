<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $rrhh = Role::firstOrCreate(['name' => 'RRHH', 'guard_name' => 'web']);
        $rrhh->syncPermissions($this->crudPermissionsFor([
            'employee', 'contract', 'contract_template', 'department', 'position',
            'attendance_day', 'attendance_event', 'attendance_mark_failure',
            'employee_leave', 'warning', 'vacation', 'schedule', 'shift_template',
            'rotation_pattern', 'holiday', 'face_enrollment', 'employee_device',
            'terminal', 'branch', 'absence',
        ]));

        $contador = Role::firstOrCreate(['name' => 'Contador/Nómina', 'guard_name' => 'web']);
        $contador->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'payroll', 'payroll_period', 'loan', 'advance',
                'merchandise_withdrawal', 'liquidacion', 'aguinaldo',
                'aguinaldo_period', 'disbursement_batch', 'deduction', 'perception',
            ]),
            $this->viewOnlyPermissionsFor(['employee', 'contract']),
        ));

        $readOnly = Role::firstOrCreate(['name' => 'Solo Lectura', 'guard_name' => 'web']);
        $readOnly->syncPermissions($this->viewOnlyPermissionsFor(PermissionSeeder::MODELS));

        $this->assignSuperAdminToUsersWithoutRoles($superAdmin);
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, string>
     */
    private function crudPermissionsFor(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            foreach (PermissionSeeder::ABILITIES as $ability) {
                $names[] = "{$ability}_{$model}";
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, string>
     */
    private function viewOnlyPermissionsFor(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            $names[] = "view_any_{$model}";
            $names[] = "view_{$model}";
        }

        return $names;
    }

    private function assignSuperAdminToUsersWithoutRoles(Role $superAdmin): void
    {
        User::doesntHave('roles')->get()->each(
            fn (User $user) => $user->assignRole($superAdmin)
        );
    }
}
