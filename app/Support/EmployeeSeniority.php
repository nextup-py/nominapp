<?php

namespace App\Support;

/**
 * Fecha de ingreso que usan los reportes de empleados. Por defecto es la antigüedad acumulada a mano (reingreso) o, si no hay, el inicio del contrato vigente; con
 * «antigüedad original» es el inicio de la relación laboral continua, para que una renovación no reinicie la antigüedad.
 */
class EmployeeSeniority
{
    /**
     * Expresión SQL de la fecha de ingreso (usa `employees` y el `contracts` del contrato vigente de la consulta).
     *
     * Con `$original`, prevalece `contracts.seniority_from` (antigüedad acumulada a mano en un reingreso) y, si no hay,
     * el primer contrato posterior a la última terminación del empleado: las renovaciones y los contratos que vencieron
     * sin baja forman parte de la misma relación; una terminación la corta.
     */
    public static function hireDateSql(bool $original): string
    {
        if (! $original) {
            return 'COALESCE(contracts.seniority_from, contracts.start_date)';
        }

        return "COALESCE(contracts.seniority_from, (
            SELECT MIN(c2.start_date) FROM contracts c2
            WHERE c2.employee_id = employees.id
              AND c2.status <> 'draft'
              AND c2.start_date <= contracts.start_date
              AND c2.start_date > COALESCE((
                  SELECT MAX(c3.start_date) FROM contracts c3
                  WHERE c3.employee_id = employees.id
                    AND c3.status = 'terminated'
                    AND c3.start_date < contracts.start_date
              ), '1900-01-01')
        ), contracts.start_date)";
    }
}
