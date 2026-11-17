<?php

namespace App\Notifications;

use App\Filament\Resources\VacationResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisos de vacaciones para RR.HH. (`vacation.pending`, `vacation.unpaid`, `vacation.unused`, `vacation.anniversary`).
 * Se crean con los datos de `VacationAlertService`; cada tipo se puede apagar y pasar a resumen por usuario.
 */
class VacationAlertNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** Tipos de aviso válidos. */
    public const KINDS = ['pending', 'unpaid', 'unused', 'anniversary'];

    /**
     * @param  string  $kind  Uno de `KINDS`.
     * @param  array<string, mixed>  $data  Resultado del método homónimo de `VacationAlertService`.
     */
    public function __construct(public readonly string $kind, public readonly array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        [$title, $body] = $this->message();

        return [
            ...FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-sun')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ir a Vacaciones')->url(VacationResource::getUrl('index')),
                ])
                ->getDatabaseMessage(),
            'kind' => $this->kind,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return "vacation.{$this->kind}";
    }

    /**
     * Título y texto según el tipo.
     *
     * @return array{0: string, 1: string}
     */
    private function message(): array
    {
        $count = (int) ($this->data['count'] ?? 0);
        $names = implode(', ', $this->data['names'] ?? []);
        $more = $count > count($this->data['names'] ?? []) ? ' y otros' : '';
        $oldest = isset($this->data['oldest']) ? $this->data['oldest']->format('d/m/Y') : '';

        return match ($this->kind) {
            'pending' => [
                $count === 1 ? '1 solicitud de vacaciones sin decidir' : "{$count} solicitudes de vacaciones sin decidir",
                "Llevan más de {$this->data['days']} días esperando aprobación o rechazo; la más antigua es del {$oldest}.",
            ],
            'unpaid' => [
                $count === 1 ? '1 vacación aprobada sin marcar como pagada' : "{$count} vacaciones aprobadas sin marcar como pagadas",
                "Su inicio fue hace más de 7 días (la más antigua empezó el {$oldest}). Si ya se pagaron por fuera, marcalas como pagadas.",
            ],
            'unused' => [
                $count === 1 ? '1 empleado con su derecho anual sin tomar' : "{$count} empleados con su derecho anual sin tomar",
                "Tienen sin usar todo el derecho de un año: {$names}{$more}. Conviene programar sus vacaciones.",
            ],
            default => [
                $count === 1 ? '1 empleado cumple su primer año' : "{$count} empleados cumplen su primer año",
                "Empiezan a tener derecho a vacaciones: {$names}{$more}.",
            ],
        };
    }
}
