<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Illuminate\Console\Command;

/**
 * Repara los contratos que quedaron en estado «Vencido» con una fecha de fin que todavía no llegó (resto de cuando
 * editar la fecha de un contrato vencido no lo devolvía a «Vigente», ver `Contract::shouldReactivateOnEdit()`).
 * Los vuelve a «Vigente» salvo que el empleado ya tenga otro contrato vigente o suspendido, para no dejarle dos.
 */
class ReactivateMisexpiredContracts extends Command
{
    protected $signature = 'contracts:reactivate-misexpired {--dry-run : Muestra qué se reactivaría sin guardar nada}';

    protected $description = 'Vuelve a «Vigente» los contratos vencidos cuya fecha de fin todavía no pasó';

    /**
     * Reactiva (o lista con --dry-run) los contratos mal vencidos y las excepciones.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reactivated = 0;
        $skipped = 0;

        Contract::query()
            ->with('employee')
            ->where('status', 'expired')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', today())
            ->orderBy('end_date')
            ->get()
            ->each(function (Contract $contract) use ($dryRun, &$reactivated, &$skipped): void {
                $name = $contract->employee?->full_name ?? "empleado #{$contract->employee_id}";
                $hasOther = Contract::query()
                    ->where('employee_id', $contract->employee_id)
                    ->whereKeyNot($contract->id)
                    ->whereIn('status', ['active', 'suspended'])
                    ->exists();

                if ($hasOther) {
                    $skipped++;
                    $this->line("  Se omite {$name} (fin {$contract->end_date->format('d/m/Y')}): ya tiene otro contrato vigente.");

                    return;
                }

                $reactivated++;
                $this->line('  '.($dryRun ? 'Se reactivaría' : 'Reactivado')." el contrato de {$name} (fin {$contract->end_date->format('d/m/Y')}).");

                if (! $dryRun) {
                    $contract->update(['status' => 'active']);
                }
            });

        $this->info(($dryRun ? '[dry-run] Se reactivarían ' : 'Reactivados ')."{$reactivated} contrato(s); {$skipped} omitido(s).");

        return self::SUCCESS;
    }
}
