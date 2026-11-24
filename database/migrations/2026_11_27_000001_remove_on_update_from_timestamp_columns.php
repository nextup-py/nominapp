<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quita `ON UPDATE CURRENT_TIMESTAMP` de las columnas `timestamp` que lo recibieron sin pedirlo.
 *
 * En MySQL/MariaDB con `explicit_defaults_for_timestamp` apagado (el valor por defecto de los servidores cPanel
 * antiguos), una columna `timestamp` declarada NOT NULL y sin default —la primera de la tabla— recibe
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Cada UPDATE de la fila la reescribía con el reloj del
 * servidor de base de datos (en su propia zona horaria, que en Macro está 2 horas atrás de la app). Caso detectado:
 * `terminal_devices.linked_at` de los dispositivos cerrados figuraba exactamente 2 horas antes de `unlinked_at`.
 * Pueden estar afectadas otras columnas del mismo tipo (`attendance_mark_failures.occurred_at`,
 * `employee_devices.linked_at`, `expires_at` de solicitudes y comandos…), por eso se corrige en bloque.
 *
 * Los valores ya pisados no se pueden recuperar. Idempotente: sin columnas afectadas no hace nada.
 */
return new class extends Migration
{
    /** Quita el `ON UPDATE` conservando si la columna admite nulos y, en las obligatorias, su `DEFAULT CURRENT_TIMESTAMP`. */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $columns = DB::select(
            "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, IS_NULLABLE AS nullable
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND EXTRA LIKE '%on update%'"
        );

        foreach ($columns as $column) {
            $definition = $column->nullable === 'YES' ? 'TIMESTAMP NULL DEFAULT NULL' : 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

            DB::statement("ALTER TABLE `{$column->tbl}` MODIFY `{$column->col}` {$definition}");
        }

        if ($columns !== []) {
            Log::info('Columnas timestamp sin ON UPDATE CURRENT_TIMESTAMP: '.collect($columns)->map(fn ($c) => "{$c->tbl}.{$c->col}")->implode(', '));
        }
    }

    /** No se restaura el `ON UPDATE`: era un efecto no deseado. */
    public function down(): void {}
};
