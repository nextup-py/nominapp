<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentsExpiringNotification;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;

/**
 * Avisa a quienes ven documentos de los legajos de los vencidos y los que vencen en `document_alert_days` días
 * (0 = apagado). Solo de empleados activos. Cada documento se avisa una vez por estado: `expiring_notified_at` y
 * `expired_notified_at` lo registran (y se reinician si cambia la fecha de vencimiento).
 */
class NotifyExpiringDocuments extends Command
{
    protected $signature = 'documents:notify-expiring';

    protected $description = 'Avisa de los documentos de legajos vencidos o por vencer (una vez por documento y estado)';

    public function handle(GeneralSettings $settings): int
    {
        $days = $settings->document_alert_days;

        if ($days <= 0) {
            $this->info('El aviso de documentos por vencer está apagado.');

            return self::SUCCESS;
        }

        $base = Document::query()->with('employee')->whereHas('employee', fn ($q) => $q->where('status', 'active'));

        $expired = (clone $base)->expired()->whereNull('expired_notified_at')->get();
        $expiring = (clone $base)->expiringWithin($days)->whereNull('expiring_notified_at')->get();

        $items = $expired->toBase()->map(fn (Document $d): array => $this->item($d, true))
            ->merge($expiring->toBase()->map(fn (Document $d): array => $this->item($d, false)))
            ->values()
            ->all();

        if ($items === []) {
            $this->info('Sin documentos nuevos para avisar.');

            return self::SUCCESS;
        }

        $recipients = User::all()->filter(fn (User $user): bool => $user->can('view_any_document'));

        foreach ($recipients as $user) {
            $user->notify(new DocumentsExpiringNotification($items));
        }

        if ($recipients->isNotEmpty()) {
            Document::whereIn('id', $expired->pluck('id'))->update(['expired_notified_at' => now()]);
            Document::whereIn('id', $expiring->pluck('id'))->update(['expiring_notified_at' => now()]);
        }

        $this->info(count($items)." documento(s) para avisar a {$recipients->count()} usuario(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{document_id: int, employee_id: int, employee: string, name: string, date: string, expired: bool}
     */
    private function item(Document $document, bool $expired): array
    {
        return [
            'document_id' => $document->id,
            'employee_id' => $document->employee_id,
            'employee' => $document->employee?->full_name ?? 'Empleado',
            'name' => $document->name,
            'date' => $document->expires_at->format('d/m/Y'),
            'expired' => $expired,
        ];
    }
}
