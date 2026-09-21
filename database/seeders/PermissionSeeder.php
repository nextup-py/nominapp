<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Siembra el catálogo de permisos CRUD (view_any/view/create/update/delete)
 * para cada uno de los 33 modelos con Resource en Filament.
 *
 * No incluye permisos de acciones de negocio (aprobar, cerrar, exportar...)
 * — esos se agregan en un seeder posterior (ver Plan 2 de roles/permisos).
 */
class PermissionSeeder extends Seeder
{
    public const MODELS = [
        'absence', 'advance', 'aguinaldo', 'aguinaldo_period',
        'attendance_day', 'attendance_event', 'attendance_mark_failure',
        'branch', 'company', 'contract', 'contract_template',
        'deduction', 'department', 'disbursement_batch',
        'employee', 'employee_device', 'employee_leave',
        'face_enrollment', 'holiday', 'liquidacion', 'loan',
        'merchandise_withdrawal', 'payroll', 'payroll_period',
        'perception', 'position', 'rotation_pattern', 'schedule',
        'shift_template', 'terminal', 'user', 'vacation', 'warning',
    ];

    public const ABILITIES = ['view_any', 'view', 'create', 'update', 'delete'];

    public const ABILITY_LABELS = [
        'view_any' => 'Ver listado',
        'view' => 'Ver detalle',
        'create' => 'Crear',
        'update' => 'Editar',
        'delete' => 'Eliminar',
    ];

    public const MODEL_LABELS = [
        'absence' => 'Ausencia',
        'advance' => 'Adelanto',
        'aguinaldo' => 'Aguinaldo',
        'aguinaldo_period' => 'Período de Aguinaldo',
        'attendance_day' => 'Día de Asistencia',
        'attendance_event' => 'Marcación de Asistencia',
        'attendance_mark_failure' => 'Falla de Marcación',
        'branch' => 'Sucursal',
        'company' => 'Empresa',
        'contract' => 'Contrato',
        'contract_template' => 'Plantilla de Contrato',
        'deduction' => 'Deducción',
        'department' => 'Departamento',
        'disbursement_batch' => 'Lote Bancario',
        'employee' => 'Empleado',
        'employee_device' => 'Dispositivo de Empleado',
        'employee_leave' => 'Permiso/Licencia',
        'face_enrollment' => 'Enrolamiento Facial',
        'holiday' => 'Feriado',
        'liquidacion' => 'Liquidación',
        'loan' => 'Préstamo',
        'merchandise_withdrawal' => 'Retiro de Mercadería',
        'payroll' => 'Nómina',
        'payroll_period' => 'Período de Nómina',
        'perception' => 'Percepción',
        'period' => 'Generación de Períodos',
        'position' => 'Cargo',
        'rotation_pattern' => 'Patrón de Rotación',
        'schedule' => 'Horario',
        'shift_template' => 'Plantilla de Turno',
        'terminal' => 'Terminal',
        'user' => 'Usuario',
        'vacation' => 'Vacación',
        'warning' => 'Amonestación',
    ];

    public const GROUPS = [
        'Organización' => ['company', 'branch', 'department', 'position', 'contract_template'],
        'Empleados' => ['employee', 'contract', 'employee_device', 'face_enrollment', 'vacation', 'warning', 'employee_leave', 'holiday'],
        'Asistencia' => ['attendance_day', 'attendance_event', 'attendance_mark_failure', 'terminal', 'schedule', 'shift_template', 'rotation_pattern'],
        'Nómina y Créditos' => ['payroll', 'payroll_period', 'period', 'deduction', 'perception', 'loan', 'advance', 'merchandise_withdrawal', 'liquidacion', 'aguinaldo', 'aguinaldo_period', 'disbursement_batch', 'absence'],
        'Configuración' => ['user'],
    ];

    public function run(): void
    {
        foreach (self::MODELS as $model) {
            foreach (self::ABILITIES as $ability) {
                Permission::firstOrCreate([
                    'name' => "{$ability}_{$model}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
