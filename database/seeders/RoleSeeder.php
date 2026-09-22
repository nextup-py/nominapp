<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $rrhh = Role::firstOrCreate(['name' => 'RRHH', 'guard_name' => 'web']);
        $rrhh->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'employee', 'contract', 'contract_template', 'department', 'position',
                'attendance_day', 'attendance_event', 'attendance_mark_failure',
                'employee_leave', 'warning', 'vacation', 'schedule', 'shift_template',
                'rotation_pattern', 'holiday', 'face_enrollment', 'employee_device',
                'terminal', 'branch', 'absence',
            ]),
            [
                'approve_employee_leave', 'reject_employee_leave',
                'justify_absence', 'mark_unjustified_absence', 'export_absence',
                'activate_contract', 'renew_contract', 'suspend_contract',
                'reactivate_contract', 'terminate_contract',
            ],
        ));

        $contador = Role::firstOrCreate(['name' => 'Contador/Nómina', 'guard_name' => 'web']);
        $contador->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'payroll', 'payroll_period', 'loan', 'advance',
                'merchandise_withdrawal', 'liquidacion', 'aguinaldo',
                'aguinaldo_period', 'disbursement_batch', 'deduction', 'perception',
            ]),
            $this->viewOnlyPermissionsFor(['employee', 'contract']),
            [
                'approve_loan', 'reject_loan', 'disburse_loan', 'cancel_loan', 'export_loan',
                'approve_advance', 'reject_advance', 'disburse_advance', 'revert_advance', 'cancel_advance', 'export_advance',
                'approve_merchandise_withdrawal', 'reject_merchandise_withdrawal', 'cancel_merchandise_withdrawal',
                'confirm_disbursement_batch', 'cancel_disbursement_batch',
                'approve_payroll', 'disburse_payroll', 'mark_paid_payroll', 'revert_payroll', 'regenerate_payroll', 'export_payroll',
                'generate_payrolls_period', 'close_payroll_period', 'reopen_payroll_period',
                'calculate_liquidacion', 'close_liquidacion', 'export_liquidacion',
                'mark_paid_aguinaldo', 'export_aguinaldo',
                'generate_aguinaldos_period', 'close_aguinaldo_period', 'reopen_aguinaldo_period',
            ],
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
