<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Resumen de documentos de los legajos que vencieron o están por vencer. Cada documento se avisa una vez al entrar en
 * cada estado (por vencer y vencido) y vuelve a avisarse si cambia su fecha de vencimiento.
 */
class DocumentsExpiringNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  list<array{document_id: int, employee_id: int, employee: string, name: string, date: string, expired: bool}>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $expired = collect($this->items)->where('expired', true);
        $expiring = collect($this->items)->where('expired', false);
        $parts = array_filter([
            $expired->isNotEmpty() ? $expired->count().' vencido(s)' : null,
            $expiring->isNotEmpty() ? $expiring->count().' por vencer' : null,
        ]);
        $lines = collect($this->items)->take(5)->map(fn (array $i): string => "{$i['employee']}: {$i['name']} ({$i['date']})")->implode('; ');
        $more = count($this->items) > 5 ? ' y '.(count($this->items) - 5).' más' : '';
        $first = $this->items[0] ?? null;

        $notification = FilamentNotification::make()
            ->title('Documentos de legajos: '.implode(' y ', $parts))
            ->body($lines.$more.'.')
            ->icon('heroicon-o-document-text')
            ->color($expired->isNotEmpty() ? 'danger' : 'warning');

        if ($first) {
            $notification->actions([
                FilamentAction::make('view')->label('Ver legajo')->url(EmployeeResource::getUrl('view', ['record' => $first['employee_id']])),
            ]);
        }

        return [...$notification->getDatabaseMessage(), 'document_ids' => array_column($this->items, 'document_id')];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'document.expiring';
    }
}
