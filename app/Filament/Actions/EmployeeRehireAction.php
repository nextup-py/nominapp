<?php

namespace App\Filament\Actions;

use App\Models\Contract;
use App\Models\Employee;
use App\Services\EmployeeRehireService;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

/**
 * Acción «Reingresar»: reactiva al empleado dado de baja y abre un contrato nuevo prellenado con los datos del último.
 * La antigüedad se acumula solo si RR.HH. lo marca en cada reingreso. Se construye con la clase de acción de tabla o de
 * página (mismo patrón que `EmployeeTerminationAction`).
 */
class EmployeeRehireAction
{
    /**
     * @param  class-string<Action|\Filament\Tables\Actions\Action>  $actionClass
     */
    public static function make(string $actionClass = Action::class): object
    {
        return $actionClass::make('rehire_employee')
            ->label('Reingresar')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->tooltip('Reactiva al empleado y abre un contrato nuevo con los datos del último')
            ->authorize(fn (): bool => auth()->user()?->can('create_contract') ?? false)
            ->visible(fn (Employee $record): bool => $record->status === 'inactive' && EmployeeRehireService::lastContract($record) !== null)
            ->modalHeading(fn (Employee $record): string => "Reingreso de {$record->full_name}")
            ->modalDescription('Se crea un contrato nuevo con el cargo, la modalidad y el pago del último. Las percepciones y deducciones que se cerraron con la baja no se restauran: asignarlas de nuevo.')
            ->modalSubmitActionLabel('Reingresar')
            ->fillForm(fn (Employee $record): array => [
                'type' => EmployeeRehireService::lastContract($record)?->type,
                'start_date' => today()->toDateString(),
                'salary' => EmployeeRehireService::lastContract($record)?->salary,
                'trial_days' => 30,
                'accumulate_seniority' => false,
            ])
            ->form(fn (Employee $record): array => [
                Select::make('type')
                    ->label('Tipo de contrato')
                    ->options(Contract::getTypeOptions())
                    ->native(false)
                    ->live()
                    ->required(),
                DatePicker::make('start_date')->label('Fecha de reingreso')->required()->live(),
                DatePicker::make('end_date')
                    ->label('Fecha de finalización')
                    ->required(fn (Get $get): bool => Contract::requiresEndDate((string) $get('type')))
                    ->visible(fn (Get $get): bool => Contract::requiresEndDate((string) $get('type')))
                    ->helperText('Plazo fijo y aprendizaje: máximo 1 año (Art. 53 CLT).'),
                TextInput::make('salary')->label('Salario')->numeric()->minValue(1)->required()->prefix('Gs.'),
                TextInput::make('trial_days')->label('Días de prueba')->numeric()->minValue(0)->maxValue(365)
                    ->helperText('0 si no lleva período de prueba.'),
                Toggle::make('accumulate_seniority')
                    ->label('Acumular la antigüedad anterior')
                    ->helperText(fn (): string => 'Decide RR.HH. en cada reingreso; si corresponde es criterio legal que confirma el asesor. Primer ingreso: '
                        .(EmployeeRehireService::firstStartDate($record)?->format('d/m/Y') ?? 'sin datos').'.'),
                Textarea::make('notes')->label('Notas')->rows(2)->maxLength(1000),
                Placeholder::make('last')
                    ->hiddenLabel()
                    ->content(fn (): string => 'Último contrato: '.(EmployeeRehireService::lastContract($record)?->start_date?->format('d/m/Y') ?? '-').' al '
                        .(EmployeeRehireService::lastContract($record)?->terminated_at?->format('d/m/Y') ?? EmployeeRehireService::lastContract($record)?->end_date?->format('d/m/Y') ?? 'sin fin').'.'),
            ])
            ->action(function (Employee $record, array $data, $action): void {
                try {
                    app(EmployeeRehireService::class)->rehire($record, $data);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo reingresar')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Reingreso registrado')
                    ->body("{$record->full_name} quedó activo con un contrato nuevo desde ".Carbon::parse($data['start_date'])->format('d/m/Y').'.')
                    ->send();
            });
    }
}
