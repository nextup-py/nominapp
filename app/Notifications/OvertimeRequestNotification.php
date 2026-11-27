<?php

namespace App\Notifications;

use App\Filament\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisos del pedido previo de horas extra: `hr` (espera la decisión de RR.HH.) y `result` (se aprobó o rechazó, para
 * quien lo pidió).
 */
class OvertimeRequestNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** @param  'hr'|'result'  $kind */
    public function __construct(public readonly OvertimeRequest $request, public readonly string $kind) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $request = $this->request->loadMissing('employee');
        $employee = trim(($request->employee?->first_name ?? '').' '.($request->employee?->last_name ?? ''));
        $detail = "{$employee} · {$request->rangeLabel()} · hasta {$request->max_hours} h por día";

        if ($this->kind === 'hr') {
            $notification = FilamentNotification::make()
                ->title('Pedido de horas extra para aprobar')
                ->body($detail)
                ->icon('heroicon-o-clock')
                ->warning();
        } else {
            $approved = $request->status === OvertimeRequest::STATUS_APPROVED;
            $notification = FilamentNotification::make()
                ->title($approved ? 'Pedido de horas extra aprobado' : 'Pedido de horas extra rechazado')
                ->body($detail.(! $approved && $request->rejection_reason ? ' · Motivo: '.$request->rejection_reason : ''))
                ->icon('heroicon-o-clock');
            $approved ? $notification->success() : $notification->danger();
        }

        return [
            ...$notification
                ->actions([FilamentAction::make('view')->label('Ver pedido')->url(OvertimeRequestResource::getUrl('view', ['record' => $request]))])
                ->getDatabaseMessage(),
            'overtime_request_id' => $request->id,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return "overtime.request_{$this->kind}";
    }
}
