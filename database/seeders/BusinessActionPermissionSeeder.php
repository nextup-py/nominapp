<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Siembra el catálogo de permisos de acciones de negocio (transiciones de
 * estado: aprobar, rechazar, cerrar, desembolsar, exportar, etc.) para los
 * 12 módulos con workflow real. No cubre CRUD (ver PermissionSeeder) ni
 * Warning (sin lifecycle).
 *
 * Nota: la clave 'period' en ACTIONS es una clave pseudo-modelo interna;
 * no es un 13º módulo real — agrupa las acciones generate_payrolls y
 * generate_aguinaldos cuya lógica de permisos diverge del patrón
 * {accion}_{modelo_real} (ver comentario en ACTIONS).
 */
class BusinessActionPermissionSeeder extends Seeder
{
    /** @var array<string, array<string, string>> modelo => [accion => label] */
    public const ACTIONS = [
        'loan' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'disburse' => 'Desembolsar',
            'cancel' => 'Cancelar',
            'export' => 'Exportar',
        ],
        'advance' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'disburse' => 'Desembolsar',
            'revert' => 'Revertir',
            'cancel' => 'Cancelar',
            'export' => 'Exportar',
        ],
        'merchandise_withdrawal' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'cancel' => 'Cancelar',
        ],
        'disbursement_batch' => [
            'confirm' => 'Confirmar',
            'cancel' => 'Cancelar',
        ],
        'payroll' => [
            'approve' => 'Aprobar',
            'disburse' => 'Desembolsar',
            'mark_paid' => 'Marcar como Pagada',
            'revert' => 'Revertir',
            'regenerate' => 'Regenerar',
            'export' => 'Exportar',
        ],
        // Clave pseudo-modelo: agrupa generate_payrolls/generate_aguinaldos porque sus permisos finales
        // (generate_payrolls_period, generate_aguinaldos_period) no siguen el patrón {accion}_{modelo_real}
        // de payroll_period/aguinaldo_period — registrada en PermissionSeeder::GROUPS['Nómina y Créditos']
        // para que sea visible en la UI de RoleResource (Task 3).
        'period' => [
            'generate_payrolls' => 'Generar Nóminas',
            'generate_aguinaldos' => 'Generar Aguinaldos',
        ],
        'payroll_period' => [
            'close' => 'Cerrar Período',
            'reopen' => 'Reabrir Período',
        ],
        'liquidacion' => [
            'calculate' => 'Calcular',
            'close' => 'Cerrar',
            'export' => 'Exportar',
        ],
        'aguinaldo' => [
            'mark_paid' => 'Marcar como Pagado',
            'export' => 'Exportar',
        ],
        'aguinaldo_period' => [
            'close' => 'Cerrar Período',
            'reopen' => 'Reabrir Período',
        ],
        'employee_leave' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
        ],
        'absence' => [
            'justify' => 'Justificar',
            'mark_unjustified' => 'Marcar como Injustificada',
            'export' => 'Exportar',
        ],
        'contract' => [
            'activate' => 'Activar',
            'renew' => 'Renovar',
            'suspend' => 'Suspender',
            'reactivate' => 'Reactivar',
            'terminate' => 'Terminar',
        ],
    ];

    public function run(): void
    {
        foreach (self::ACTIONS as $model => $actions) {
            foreach (array_keys($actions) as $action) {
                Permission::firstOrCreate([
                    'name' => "{$action}_{$model}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
