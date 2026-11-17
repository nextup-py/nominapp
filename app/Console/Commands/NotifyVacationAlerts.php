<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\VacationAlertNotification;
use App\Services\VacationAlertService;
use Illuminate\Console\Command;

/**
 * Avisa a quienes pueden editar vacaciones de lo que requiere atención: solicitudes sin decidir, aprobadas sin
 * pagar, empleados con el derecho anual sin tomar (solo los lunes) y quienes cumplen su primer año. Cada aviso
 * se activa y se dimensiona en Configuración de nómina; no se repite mientras el destinatario tenga uno sin leer.
 */
class NotifyVacationAlerts extends Command
{
    protected $signature = 'vacations:notify
                            {--dry-run : Muestra qué se avisaría sin enviar nada}
                            {--force-weekly : Incluye el aviso semanal aunque hoy no sea lunes}';

    protected $description = 'Avisa a RR.HH. de vacaciones pendientes, sin pagar, sin tomar y primeros aniversarios';

    public function handle(VacationAlertService $alerts): int
    {
        $weekly = $this->option('force-weekly') || now()->isMonday();

        $found = array_filter([
            'pending' => $alerts->pending(),
            'unpaid' => $alerts->unpaid(),
            'unused' => $weekly ? $alerts->unused() : null,
            'anniversary' => $alerts->anniversary(),
        ]);

        if ($found === []) {
            $this->info('No hay avisos de vacaciones para enviar.');

            return self::SUCCESS;
        }

        $recipients = User::all()->filter(fn (User $user): bool => $user->can('update_vacation'));

        foreach ($found as $kind => $data) {
            $this->line("{$kind}: {$data['count']} caso(s) → {$recipients->count()} destinatario(s).");

            if ($this->option('dry-run')) {
                continue;
            }

            foreach ($recipients as $user) {
                if (! $this->hasUnread($user, $kind)) {
                    $user->notify(new VacationAlertNotification($kind, $data));
                }
            }
        }

        return self::SUCCESS;
    }

    /** Si el usuario ya tiene un aviso de ese tipo sin leer. */
    private function hasUnread(User $user, string $kind): bool
    {
        return $user->unreadNotifications()
            ->where('type', VacationAlertNotification::class)
            ->get()
            ->contains(fn ($notification): bool => ($notification->data['kind'] ?? null) === $kind);
    }
}
