<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el horario de atención a las sucursales (`watch_days`, `watch_from`, `watch_to`): los
     * terminales sin horario de vigilancia propio usan el de su sucursal.
     *
     * Como dato inicial, una sucursal hereda el horario de sus terminales cuando TODOS comparten
     * exactamente el mismo; si alguno difiere o no tiene, la sucursal queda sin horario y no cambia
     * el comportamiento de ningún terminal.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'watch_days')) {
                $table->json('watch_days')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('branches', 'watch_from')) {
                $table->time('watch_from')->nullable()->after('watch_days');
            }
            if (! Schema::hasColumn('branches', 'watch_to')) {
                $table->time('watch_to')->nullable()->after('watch_from');
            }
        });

        DB::table('terminals')
            ->get(['branch_id', 'watch_days', 'watch_from', 'watch_to'])
            ->groupBy('branch_id')
            ->each(function ($terminals, $branchId): void {
                $signatures = $terminals->map(function ($t): ?string {
                    $days = collect(json_decode((string) $t->watch_days, true) ?: [])->map(fn ($d) => (int) $d)->sort()->values()->all();

                    return $days === [] && ! $t->watch_from && ! $t->watch_to
                        ? null
                        : json_encode([$days, $t->watch_from, $t->watch_to]);
                })->unique();

                if ($signatures->count() !== 1 || $signatures->first() === null) {
                    return;
                }

                $first = $terminals->first();
                $days = collect(json_decode((string) $first->watch_days, true) ?: [])->map(fn ($d) => (int) $d)->sort()->values()->all();

                DB::table('branches')->where('id', $branchId)->update([
                    'watch_days' => $days === [] ? null : json_encode($days),
                    'watch_from' => $first->watch_from,
                    'watch_to' => $first->watch_to,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            foreach (['watch_to', 'watch_from', 'watch_days'] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
