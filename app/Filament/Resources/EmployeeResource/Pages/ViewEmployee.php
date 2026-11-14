<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Carga el empleado con sus relaciones para evitar N+1 en el infolist.
     */
    protected function resolveRecord(int|string $key): Employee
    {
        return Employee::with([
            'branch.company',
            'activeContract.position.department',
        ])->findOrFail($key);
    }

    /**
     * Define las acciones disponibles en la vista de detalle del empleado.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_legajo')
                ->label('Descargar Legajo')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('employees.legajo', $this->record))
                ->openUrlInNewTab(),

            Action::make('enable_mobile')
                ->label('Habilitar celular')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('info')
                ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
                ->visible(fn (): bool => $this->record->status === 'active' && ! $this->record->canMarkFromMobile())
                ->requiresConfirmation()
                ->modalHeading('Habilitar la marcación desde el celular')
                ->modalDescription(fn (): string => "Hoy {$this->record->full_name} no puede marcar desde el celular ({$this->record->mobileMarkingLabel()}). Se habilitará solo para esta persona: después abre el enlace de vinculación con su cédula y fecha de nacimiento.")
                ->modalSubmitActionLabel('Sí, habilitar')
                ->action(function (): void {
                    $this->record->enableMobileMarking();
                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title('Celular habilitado')
                        ->body("{$this->record->full_name} ya puede vincular su celular. Avisale para que lo haga.")
                        ->persistent()
                        ->actions([
                            NotificationAction::make('send_whatsapp')
                                ->label('Avisar por WhatsApp')
                                ->icon('heroicon-o-chat-bubble-left-right')
                                ->color('success')
                                ->url($this->record->mobileLinkWhatsappUrl())
                                ->openUrlInNewTab()
                                ->visible(filled($this->record->mobileLinkWhatsappUrl())),
                        ])
                        ->send();
                }),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
