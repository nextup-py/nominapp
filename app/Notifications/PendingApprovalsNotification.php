<?php

namespace App\Notifications;

use App\Filament\Resources\AdvanceResource;
use App\Filament\Resources\EmployeeLeaveResource;
use App\Filament\Resources\LoanResource;
use App\Filament\Resources\MerchandiseWithdrawalResource;
use App\Filament\Resources\OvertimeRequestResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Resumen diario de las solicitudes que esperan una decisión de quien las aprueba: adelantos, préstamos, retiros de
 * mercadería, permisos y pedidos de horas extra. Cada usuario recibe solo los tipos que puede aprobar (`approve_*`); no se repite mientras el
 * aviso anterior siga sin leer.
 */
class PendingApprovalsNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * Tipos que se resumen: clave => [singular, plural, permiso para aprobarlos].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function types(): array
    {
        return [
            'advances' => ['adelanto', 'adelantos', 'approve_advance'],
            'loans' => ['préstamo', 'préstamos', 'approve_loan'],
            'merchandise_withdrawals' => ['retiro de mercadería', 'retiros de mercadería', 'approve_merchandise_withdrawal'],
            'employee_leaves' => ['permiso', 'permisos', 'approve_employee_leave'],
            'overtime_requests' => ['pedido de horas extra', 'pedidos de horas extra', 'approve_overtime_request'],
        ];
    }

    /** @param  array<string, int>  $counts  Cantidad pendiente por tipo (claves de `types()`); los tipos en cero se omiten. */
    public function __construct(public readonly array $counts) {}

    /** Total de solicitudes pendientes de todos los tipos. */
    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del panel la muestre
     * (ver TerminalProvisionedNotification::toDatabase()).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $parts = [];
        $actions = [];
        $urls = [
            'advances' => fn () => AdvanceResource::getUrl('index'),
            'loans' => fn () => LoanResource::getUrl('index', ['activeTab' => 'pending']),
            'merchandise_withdrawals' => fn () => MerchandiseWithdrawalResource::getUrl('index', ['activeTab' => 'pending']),
            'employee_leaves' => fn () => EmployeeLeaveResource::getUrl('index', ['activeTab' => 'pending']),
            'overtime_requests' => fn () => OvertimeRequestResource::getUrl('index', ['activeTab' => 'pending_hr']),
        ];

        foreach (self::types() as $key => [$singular, $plural]) {
            $count = (int) ($this->counts[$key] ?? 0);

            if ($count === 0) {
                continue;
            }

            $label = $count === 1 ? $singular : $plural;
            $parts[] = "{$count} {$label}";
            $actions[] = FilamentAction::make("view_{$key}")->label('Ver '.$plural)->url($urls[$key]());
        }

        $total = $this->total();

        return [
            ...FilamentNotification::make()
                ->title($total === 1 ? '1 solicitud espera tu aprobación' : "{$total} solicitudes esperan tu aprobación")
                ->body(implode(' · ', $parts))
                ->icon('heroicon-o-clipboard-document-check')
                ->warning()
                ->actions($actions)
                ->getDatabaseMessage(),
            'pending_approvals_count' => $total,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'approvals.pending';
    }
}
