<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeChild;
use Illuminate\Console\Command;

/**
 * Genera registros placeholder de hijos para empleados que tienen children_count > 0
 * pero aún no tienen hijos registrados individualmente.
 *
 * Los placeholders se crean con `is_placeholder = true` y sin fecha de nacimiento: no son
 * elegibles para la bonificación familiar hasta que RR.HH. cargue los datos reales desde
 * el panel (al editarlos se desmarcan). Como crean registros en la base, exigen `--force`
 * (o confirmación interactiva); `--dry-run` solo lista.
 */
class GenerateChildrenPlaceholders extends Command
{
    protected $signature = 'employees:generate-children-placeholders
                            {--dry-run : Muestra lo que se haría sin crear registros}
                            {--force : Crea los placeholders sin pedir confirmación}';

    protected $description = 'Genera placeholders de hijos para empleados con children_count > 0 sin hijos registrados';

    /**
     * Ejecuta el comando. Saltea empleados que ya tienen al menos un hijo registrado.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->components->warn('Modo dry-run: no se crearán registros.');
        }

        $employees = Employee::where('children_count', '>', 0)
            ->doesntHave('children')
            ->get();

        if ($employees->isEmpty()) {
            $this->components->info('No hay empleados que requieran placeholders.');

            return self::SUCCESS;
        }

        $this->components->info("Se encontraron {$employees->count()} empleado(s) con hijos sin registrar.");

        $totalCreated = 0;

        if (! $dryRun && ! $this->option('force')) {
            $this->components->warn('Los placeholders no generan bonificación familiar hasta que RR.HH. cargue los datos reales de cada hijo.');

            if (! $this->input->isInteractive() || ! $this->components->confirm('¿Crear los placeholders?', false)) {
                $this->components->error('Cancelado. Use --force para crear sin confirmación o --dry-run para ver el detalle.');

                return self::FAILURE;
            }
        }

        foreach ($employees as $employee) {
            $count = (int) $employee->children_count;

            $this->line("  → {$employee->full_name} (CI: {$employee->ci}) — {$count} placeholder(s)");

            if (! $dryRun) {
                for ($i = 1; $i <= $count; $i++) {
                    $firstName = $count === 1 ? 'Hijo/a' : "Hijo/a {$i}";

                    EmployeeChild::create([
                        'employee_id' => $employee->id,
                        'first_name' => $firstName,
                        'last_name' => $employee->last_name,
                        'birth_date' => null,
                        'is_placeholder' => true,
                    ]);
                }

                $totalCreated += $count;
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->components->warn('Dry-run finalizado. Ejecutá sin --dry-run para crear los registros.');
        } else {
            $this->newLine();
            $this->components->success("Se crearon {$totalCreated} placeholder(s) en {$employees->count()} empleado(s).");
            $this->components->warn('Quedan pendientes de completar (no pagan bonificación familiar): cargá los datos reales desde la pestaña Hijos de cada empleado.');
        }

        return self::SUCCESS;
    }
}
