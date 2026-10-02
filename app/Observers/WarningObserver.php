<?php

namespace App\Observers;

use App\Models\Warning;
use App\Services\SuspensionService;

/**
 * Mantiene sincronizada la suspensión disciplinaria (deducciones SUS-DIS y días suspendidos)
 * con los campos de la amonestación.
 */
class WarningObserver
{
    /** @var array<int, string> Campos que, al cambiar, obligan a recalcular la suspensión. */
    private const SUSPENSION_FIELDS = ['employee_id', 'suspension_start_date', 'suspension_days', 'suspension_summary_done'];

    /**
     * @var array<int, true> IDs de objeto (spl_object_id) de las amonestaciones pendientes de sincronizar.
     *                       Estático porque Laravel resuelve una instancia nueva del observer por cada evento.
     */
    private static array $pendingSync = [];

    public function __construct(private SuspensionService $service) {}

    /**
     * Valida la suspensión antes de persistir; lanza ValidationException si no es aplicable.
     */
    public function saving(Warning $warning): void
    {
        if ((int) $warning->suspension_days === 0) {
            $warning->suspension_start_date = null;
            $warning->suspension_summary_done = false;
        }

        if ($this->needsSync($warning)) {
            $this->service->validate($warning);
            self::$pendingSync[spl_object_id($warning)] = true;
        }
    }

    /** Aplica la suspensión tras crear o modificar sus campos. */
    public function saved(Warning $warning): void
    {
        if (! isset(self::$pendingSync[spl_object_id($warning)])) {
            return;
        }

        unset(self::$pendingSync[spl_object_id($warning)]);

        $this->service->apply($warning);
    }

    /** Revierte deducciones y días de suspensión al eliminar la amonestación. */
    public function deleting(Warning $warning): void
    {
        $this->service->revert($warning);
    }

    private function needsSync(Warning $warning): bool
    {
        return $warning->exists
            ? $warning->isDirty(self::SUSPENSION_FIELDS)
            : (int) $warning->suspension_days > 0;
    }
}
