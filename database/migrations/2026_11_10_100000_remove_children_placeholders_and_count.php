<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Elimina los hijos de relleno y la columna `employees.children_count`.
 *
 * Antes de quitar la columna guarda en `storage/app` un CSV con los empleados que tenían hijos
 * declarados, para armar la plantilla de carga de hijos reales. Solo se borran filas de relleno
 * inequívocas (nombre «Hijo/a…», apellido igual al del empleado, nacimiento 2015-01-01, sin cédula
 * ni certificado); si RR.HH. editó alguna, no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'children_count')) {
            $this->exportDeclaredChildren();
        }

        DB::table('employee_children')
            ->whereIn('id', function ($query) {
                $query->select('c.id')
                    ->from('employee_children as c')
                    ->join('employees as e', 'e.id', '=', 'c.employee_id')
                    ->where(fn ($q) => $q->where('c.first_name', 'Hijo/a')->orWhere('c.first_name', 'like', 'Hijo/a %'))
                    ->whereColumn('c.last_name', 'e.last_name')
                    ->whereDate('c.birth_date', '2015-01-01')
                    ->whereNull('c.ci')
                    ->whereNull('c.birth_certificate_path');
            })
            ->delete();

        if (Schema::hasColumn('employees', 'children_count')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('children_count');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'children_count')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->tinyInteger('children_count')->unsigned()->default(0)->after('gender');
            });
        }
    }

    /** Guarda el CSV con cédula, nombre y cantidad de hijos declarados por empleado. */
    private function exportDeclaredChildren(): void
    {
        $rows = DB::table('employees')
            ->where('children_count', '>', 0)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['ci', 'first_name', 'last_name', 'children_count']);

        if ($rows->isEmpty()) {
            return;
        }

        $csv = "ci_empleado,nombre,apellido,hijos_declarados\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->ci,
                '"'.str_replace('"', '""', (string) $row->first_name).'"',
                '"'.str_replace('"', '""', (string) $row->last_name).'"',
                $row->children_count,
            ])."\n";
        }

        Storage::disk('local')->put('hijos-declarados-'.now()->format('Ymd-His').'.csv', $csv);
    }
};
