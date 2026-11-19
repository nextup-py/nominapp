<?php

namespace App\Filament\Resources\VacationResource\Pages;

use App\Filament\Resources\VacationResource;
use App\Models\Employee;
use App\Models\VacationBalance;
use App\Services\VacationService;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditVacation extends EditRecord
{
    protected static string $resource = VacationResource::class;

    /**
     * Redirige a la vista si la vacación ya fue aprobada.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->status === 'approved') {
            Notification::make()
                ->warning()
                ->title('No editable')
                ->body('Las vacaciones aprobadas no pueden modificarse.')
                ->send();

            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }

    /** Título de la página con el nombre del empleado de la vacación. */
    public function getTitle(): string|Htmlable
    {
        return 'Editar vacación: '.($this->record->employee?->full_name ?? 'sin empleado');
    }

    /**
     * Define las acciones del encabezado de la página.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            DeleteAction::make()
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading('¿Eliminar vacaciones?')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->before(function () {
                    VacationService::releaseOnDelete($this->record);
                }),
        ];
    }

    /**
     * Define la URL a la que se redirige después de guardar.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Mutar los datos del formulario antes de guardarlos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['employee_id']) && ! empty($data['start_date'])) {
            $employee = Employee::find($data['employee_id']);
            if (! $employee instanceof Employee) {
                return $data;
            }
            $year = Carbon::parse($data['start_date'])->year;
            $businessDays = (int) ($data['business_days'] ?? 0);
            $oldBalanceId = $this->record->vacation_balance_id;
            $oldPendingDays = $this->record->isPending() ? (int) ($this->record->business_days ?? 0) : 0;
            $balance = VacationService::findBalanceToDebit($employee, $businessDays, $year, $oldBalanceId, $oldPendingDays);
            $data['vacation_balance_id'] = $balance->id;
        }

        return $data;
    }

    /**
     * Ajusta el balance de días pendientes si cambiaron los días o el balance asignado.
     */
    protected function beforeSave(): void
    {
        $record = $this->record;

        if (! $record->isPending()) {
            return;
        }

        $oldBusinessDays = (int) ($record->business_days ?? 0);
        $newBusinessDays = (int) ($this->data['business_days'] ?? 0);
        $oldBalanceId = $record->vacation_balance_id;
        $newBalanceId = $this->data['vacation_balance_id'] ?? $oldBalanceId;

        if ($oldBalanceId && $oldBalanceId !== $newBalanceId) {
            VacationBalance::find($oldBalanceId)?->releasePendingDays($oldBusinessDays);
            VacationBalance::find($newBalanceId)?->addPendingDays($newBusinessDays);
        } elseif ($oldBusinessDays !== $newBusinessDays && $record->vacationBalance) {
            $record->vacationBalance->releasePendingDays($oldBusinessDays);
            $record->vacationBalance->addPendingDays($newBusinessDays);
        }
    }

    /**
     * Define el título de la notificación después de guardar.
     */
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Solicitud de vacaciones actualizada';
    }
}
