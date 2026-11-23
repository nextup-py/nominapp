<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Notifications\Concerns\HonorsPreferences;
use App\Services\TerminalHealthService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso por la campanita sobre un cambio de salud de un terminal (ver
 * `TerminalHealthService`): `offline`, `recovered`, `unlinked`, `queue_stuck`, `battery_low`, `no_enrolled`, `cache_empty`, `clock_skew`, `app_outdated`, `not_installed` o `tab_hidden`.
 */
class TerminalHealthNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly Terminal $terminal,
        public readonly string $alert,
        public readonly bool $reminder = false,
    ) {}

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver
     * `TerminalProvisionedNotification::toDatabase()` por el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = "\"{$this->terminal->name}\"".($this->terminal->branch ? " ({$this->terminal->branch->name})" : '');

        $notification = FilamentNotification::make()
            ->actions([
                FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
            ]);

        match ($this->alert) {
            'offline' => $notification
                ->title($this->reminder ? 'Terminal sigue sin conexión' : 'Terminal sin conexión')
                ->body($this->reminder
                    ? "El terminal {$name} sigue sin reportar hace más de ".TerminalHealthService::OFFLINE_REMINDER_HOURS.' horas y nadie lo atendió: mientras tanto no puede registrar marcaciones. Causa probable: '.implode(' ', $this->terminal->offlineHints()).$this->responsibleLine()
                    : "El terminal {$name} no reporta hace más de {$this->terminal->effectiveStaleMinutes()} minutos. Causa probable: ".implode(' ', $this->terminal->offlineHints()).$this->responsibleLine())
                ->icon('heroicon-o-signal-slash')
                ->danger(),
            'recovered' => $notification
                ->title('Terminal reconectado')
                ->body("El terminal {$name} volvió a reportar.")
                ->icon('heroicon-o-signal')
                ->success(),
            'unlinked' => $notification
                ->title('Terminal sin vincular')
                ->body("El terminal {$name} perdió su acceso (se desvinculó, venció o se desactivó) y no puede marcar hasta volver a vincularlo.")
                ->icon('heroicon-o-link-slash')
                ->danger(),
            'queue_stuck' => $notification
                ->title('Cola de marcaciones atascada')
                ->body("El terminal {$name} tiene {$this->terminal->last_pending_events_count} marcación(es) pendiente(s) que no se envían hace más del tiempo configurado.")
                ->icon('heroicon-o-queue-list')
                ->warning(),
            'battery_low' => $notification
                ->title($this->reminder ? 'Terminal sigue con la batería baja' : 'Batería baja en terminal')
                ->body($this->reminder
                    ? "El terminal {$name} sigue al {$this->terminal->reportedBatteryLevel()}% y sin cargador hace más de ".TerminalHealthService::BATTERY_REMINDER_HOURS.' horas: está por apagarse y dejar de marcar. Pedir que lo enchufen.'.$this->responsibleLine()
                    : "El terminal {$name} está al {$this->terminal->reportedBatteryLevel()}% y sin cargador.".$this->responsibleLine())
                ->icon('heroicon-o-battery-0')
                ->warning(),
            'no_enrolled' => $notification
                ->title('Terminal sin empleados enrolados')
                ->body("La sucursal del terminal {$name} no tiene ningún empleado activo con rostro registrado: no podrá identificar a nadie. Enrolar a los empleados o revisar su sucursal.")
                ->icon('heroicon-o-user-group')
                ->warning(),
            'cache_empty' => $notification
                ->title('Terminal sin empleados en caché')
                ->body("El terminal {$name} está en línea pero reporta 0 empleados en su caché, aunque su sucursal tiene empleados enrolados. Enviar el comando remoto Limpiar caché o Recargar app.")
                ->icon('heroicon-o-circle-stack')
                ->warning(),
            'clock_skew' => $notification
                ->title('Reloj del terminal desajustado')
                ->body("El reloj del terminal {$name} difiere del servidor en ".($this->terminal->reportedClockSkewSeconds() ?? 0).' segundos. Revisar la fecha y hora del dispositivo (activar la hora automática).')
                ->icon('heroicon-o-clock')
                ->warning(),
            'app_outdated' => $notification
                ->title('Terminal con la app desactualizada')
                ->body("El terminal {$name} sigue con una versión anterior de la app. Enviar el comando remoto Recargar app para actualizarlo.")
                ->icon('heroicon-o-arrow-path')
                ->warning(),
            'not_installed' => $notification
                ->title('Terminal en una pestaña del navegador')
                ->body("El terminal {$name} lleva horas abierto en una pestaña del navegador, sin instalar como app ni en modo kiosko: puede quedar oculto o suspenderse y dejar de marcar. Instalarlo como app o usar el modo kiosko (ver la guía de terminales).")
                ->icon('heroicon-o-window')
                ->warning(),
            'outside_branch' => $notification
                ->title('Terminal fuera de la sucursal')
                ->body($this->outsideBody($name))
                ->icon('heroicon-o-map-pin')
                ->danger(),
            'no_charger' => $notification
                ->title('Terminal sin cargador')
                ->body("El terminal {$name} lleva más de ".app(GeneralSettings::class)->terminal_no_charger_minutes.' minutos sin cargador'.($this->terminal->reportedBatteryLevel() !== null ? " (batería al {$this->terminal->reportedBatteryLevel()}%)" : '').'. Enchufarlo y dejar la pantalla encendida y la app visible para que no se suspenda y deje de marcar.')
                ->icon('heroicon-o-bolt-slash')
                ->warning(),
            'tab_hidden' => $notification
                ->title('Pestaña del terminal oculta')
                ->body("La pestaña del terminal {$name} lleva horas oculta o minimizada: el navegador la frena y suelta el bloqueo de pantalla, así que el equipo puede suspenderse. Dejarlo a la vista o usar el modo kiosko.")
                ->icon('heroicon-o-eye-slash')
                ->warning(),
            default => $notification->title('Aviso de terminal')->body("Cambió el estado del terminal {$name}.")->icon('heroicon-o-computer-desktop'),
        };

        return [
            ...$notification->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
            'alert' => $this->alert,
        ];
    }

    /** Texto del aviso de un equipo móvil fuera de la sucursal, con la distancia de su última lectura. */
    private function outsideBody(string $name): string
    {
        $location = $this->terminal->locationStatus();
        $distance = $location !== null ? " a {$location['distance_m']} m de la sucursal (radio de {$location['radius_m']} m)" : ' fuera de la sucursal';

        return "El equipo móvil {$name} está{$distance}. Las marcaciones hechas desde ahí no son de la sucursal: recuperarlo y devolverlo, o quitarle la marca de equipo móvil si ya no se usa así.";
    }

    /** Frase final del aviso de desconexión con a quién llamar, vacía si el terminal no tiene responsable cargado. */
    private function responsibleLine(): string
    {
        $contact = $this->terminal->responsibleContact();

        return $contact ? " Responsable del equipo: {$contact}." : '';
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return "terminal.{$this->alert}";
    }
}
