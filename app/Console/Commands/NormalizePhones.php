<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Lleva los teléfonos ya cargados (empleados, empresas, sucursales y responsables de terminales) al formato único
 * del sistema (`PhoneNormalizer`). Lista aparte los que siguen inválidos para corregirlos a mano.
 */
class NormalizePhones extends Command
{
    protected $signature = 'phones:normalize {--dry-run : Muestra qué cambiaría sin guardar nada}';

    protected $description = 'Normaliza los teléfonos guardados al formato de Paraguay (0 inicial, solo dígitos)';

    /** @var array<string, array{0: class-string<Model>, 1: string}> */
    private const TARGETS = [
        'Empleados' => [Employee::class, 'phone'],
        'Empresas' => [Company::class, 'phone'],
        'Sucursales' => [Branch::class, 'phone'],
        'Terminales (responsable)' => [Terminal::class, 'responsible_phone'],
    ];

    /**
     * Recorre cada tabla, corrige lo que se puede y reporta los conteos y los que quedan inválidos.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invalid = [];

        foreach (self::TARGETS as $label => [$model, $column]) {
            $changed = 0;

            $model::query()->whereNotNull($column)->where($column, '!=', '')->each(function ($record) use ($column, $dryRun, $label, &$changed, &$invalid): void {
                $original = (string) $record->getRawOriginal($column);
                $clean = PhoneNormalizer::normalize($original);

                if ($clean !== $original) {
                    $changed++;

                    if (! $dryRun) {
                        $record->timestamps = false;
                        $record->forceFill([$column => $clean])->saveQuietly();
                    }
                }

                if (! PhoneNormalizer::isValid($clean)) {
                    $invalid[] = [$label, $record->getKey(), $original];
                }
            });

            $this->line(sprintf('%s: %d %s', $label, $changed, $dryRun ? 'a corregir' : 'corregidos'));
        }

        if ($invalid !== []) {
            $this->newLine();
            $this->warn(count($invalid).' teléfonos siguen inválidos (corregir a mano):');
            $this->table(['Tabla', 'ID', 'Valor'], $invalid);
        }

        return self::SUCCESS;
    }
}
