<?php

namespace App\Console\Commands;

use App\Services\ContractConsistencyService;
use Illuminate\Console\Command;

/**
 * Regulariza los contratos «Vigentes» de empleados inactivos. Cierra (Terminado, fecha = última asistencia) los que no
 * tienen liquidación abierta; los que sí la tienen se informan, porque se cierran desde Liquidación; los de empleados
 * inexistentes quedan para revisión manual.
 */
class CloseStaleContracts extends Command
{
    protected $signature = 'contracts:close-stale {--dry-run : Muestra qué se cerraría sin guardar nada}';

    protected $description = 'Termina los contratos vigentes de empleados inactivos que no tienen liquidación abierta';

    /**
     * Lista y, sin --dry-run, cierra los contratos desactualizados.
     */
    public function handle(ContractConsistencyService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;
        $pending = 0;
        $orphans = 0;

        foreach ($service->staleContracts() as $row) {
            $contract = $row['contract'];
            $name = $contract->employee?->full_name ?? "empleado #{$contract->employee_id} (inexistente)";

            if ($row['case'] === ContractConsistencyService::CASE_ORPHAN) {
                $orphans++;
                $this->line("  Revisar a mano: contrato #{$contract->id}, {$name}.");

                continue;
            }

            if ($row['case'] === ContractConsistencyService::CASE_PENDING_LIQUIDATION) {
                $pending++;
                $this->line("  Cerrar la liquidación de {$name} (contrato #{$contract->id}).");

                continue;
            }

            $closed++;
            $date = $row['last_attendance']?->format('d/m/Y') ?? 'hoy (nunca marcó)';
            $this->line('  '.($dryRun ? 'Se cerraría' : 'Cerrado')." el contrato #{$contract->id} de {$name} con baja el {$date}.");

            if (! $dryRun) {
                $service->close($contract, $row['last_attendance']);
            }
        }

        $this->info(($dryRun ? '[dry-run] Se cerrarían ' : 'Cerrados ')."{$closed}; {$pending} esperan el cierre de su liquidación; {$orphans} a revisar a mano.");

        return self::SUCCESS;
    }
}
