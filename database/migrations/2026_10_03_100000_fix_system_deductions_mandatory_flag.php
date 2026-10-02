<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Códigos de deducciones de sistema (ver Deduction::SYSTEM_CODES). Se repiten como literales para
     * que la migración no dependa del código de la aplicación.
     *
     * @var array<int, string>
     */
    private const SYSTEM_CODES = ['AUS-INJ', 'SUS-DIS', 'PRE001', 'ADE001', 'MER001'];

    /**
     * Quita el flag `is_mandatory` a las deducciones de sistema y elimina las asignaciones vacías que
     * produjo: `AUS-INJ` se creaba obligatoria y se asignaba a cada empleado nuevo como deducción abierta
     * (sin fin ni monto), generando una línea en cero en cada nómina. Las deducciones reales (con monto
     * o con fecha de fin, de un solo día) no se tocan. Idempotente.
     */
    public function up(): void
    {
        $affectedIds = DB::table('deductions')
            ->whereIn('code', self::SYSTEM_CODES)
            ->where('is_mandatory', true)
            ->pluck('id');

        if ($affectedIds->isEmpty()) {
            return;
        }

        DB::table('employee_deductions')
            ->whereIn('deduction_id', $affectedIds)
            ->whereNull('end_date')
            ->whereNull('custom_amount')
            ->delete();

        DB::table('deductions')->whereIn('id', $affectedIds)->update(['is_mandatory' => false]);
    }

    /** Los datos eliminados eran asignaciones erróneas: no se restauran. */
    public function down(): void {}
};
