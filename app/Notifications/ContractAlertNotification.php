<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Notificación de alerta por contrato próximo a vencer o ya vencido. */
class ContractAlertNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** @param  'expiring'|'expired'  $alertType */
    public function __construct(
        public readonly Contract $contract,
        public readonly string $alertType,
    ) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` (no un
     * array plano) — la campanita del panel filtra por `data->format =
     * 'filament'`, que solo ese builder genera (ver
     * TerminalProvisionedNotification::toDatabase() para el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $employee = $this->contract->employee;
        $employeeName = $employee?->full_name ?? 'Empleado desconocido';

        if ($this->alertType === 'expired') {
            $message = "El contrato de {$employeeName} venció el {$this->contract->end_date->format('d/m/Y')}.";
            $icon = 'heroicon-o-x-circle';
            $status = 'danger';
        } else {
            $days = $this->contract->remaining_days;
            $message = "El contrato de {$employeeName} vence en {$days} ".($days === 1 ? 'día' : 'días')
                ." ({$this->contract->end_date->format('d/m/Y')}).";
            $icon = 'heroicon-o-clock';
            $status = 'warning';
        }

        return [
            ...FilamentNotification::make()
                ->title($this->alertType === 'expired' ? 'Contrato vencido' : 'Contrato por vencer')
                ->body($message)
                ->icon($icon)
                ->status($status)
                ->actions([
                    FilamentAction::make('view')->label('Ver contrato')->url("/admin/contratos/{$this->contract->id}"),
                ])
                ->getDatabaseMessage(),
            // Datos adicionales para lógica de deduplicación
            'contract_id' => $this->contract->id,
            'alert_type' => $this->alertType,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'contract.alert';
    }
}
