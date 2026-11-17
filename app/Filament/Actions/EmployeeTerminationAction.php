<?php

namespace App\Filament\Actions;

use App\Filament\Resources\LiquidacionResource;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Services\EmployeeTerminationService;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

/**
 * Acción «Dar de baja»: pide fecha, tipo y motivo, termina el contrato y deja inactivo al empleado. Con «Calcular la
 * liquidación en el sistema» no da de baja todavía: lleva al formulario de Liquidación con los datos cargados (la
 * liquidación exige un contrato activo, y su cierre es lo que termina el contrato). Se construye con la clase de acción
 * de tabla o de página (mismo patrón que `AbsenceActions`) y usa el `$record` inyectado.
 */
class EmployeeTerminationAction
{
    /**
     * @param  class-string<Action|\Filament\Tables\Actions\Action>  $actionClass
     */
    public static function make(string $actionClass = Action::class): object
    {
        return $actionClass::make('terminate_employment')
            ->label('Dar de baja')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->tooltip('Termina el contrato y deja inactivo al empleado, con fecha, tipo y motivo')
            ->authorize(fn (): bool => auth()->user()?->can('terminate_contract') ?? false)
            ->visible(fn (Employee $record): bool => $record->status === 'active')
            ->modalHeading(fn (Employee $record): string => "Dar de baja a {$record->full_name}")
            ->modalSubmitActionLabel('Dar de baja')
            ->form(fn (Employee $record): array => [
                Placeholder::make('pending')
                    ->hiddenLabel()
                    ->content(fn (): string => self::pendingText($record))
                    ->visible(fn (): bool => self::pendingText($record) !== ''),
                DatePicker::make('date')
                    ->label('Fecha de baja')
                    ->default(today())
                    ->maxDate(today())
                    ->required(),
                Select::make('type')
                    ->label('Tipo de baja')
                    ->options(Liquidacion::getTerminationTypeOptions())
                    ->native(false)
                    ->required(),
                Textarea::make('reason')
                    ->label('Motivo')
                    ->rows(3)
                    ->maxLength(1000),
                Toggle::make('calculate_liquidation')
                    ->label('Calcular la liquidación en el sistema')
                    ->helperText('Opcional. Te lleva al formulario de Liquidación con estos datos; el contrato se termina al cerrar la liquidación. Si se liquida por fuera, dejalo apagado.')
                    ->default(false),
            ])
            ->action(function (Employee $record, array $data, $action): void {
                if ($data['calculate_liquidation'] ?? false) {
                    $url = LiquidacionResource::getUrl('create', [
                        'employee_id' => $record->id,
                        'termination_date' => $data['date'],
                        'termination_type' => $data['type'],
                        'termination_reason' => $data['reason'] ?? null,
                    ]);

                    method_exists($action, 'redirect') && $action->redirect($url);

                    return;
                }

                try {
                    app(EmployeeTerminationService::class)->terminate($record, Carbon::parse($data['date']), $data['type'], $data['reason'] ?? null);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo dar de baja')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Baja registrada')
                    ->body("{$record->full_name} quedó inactivo. El contrato se terminó con fecha ".Carbon::parse($data['date'])->format('d/m/Y').'.')
                    ->send();
            });
    }

    /** Aviso de préstamos y adelantos pendientes, o cadena vacía si no hay. */
    private static function pendingText(Employee $employee): string
    {
        $pending = EmployeeTerminationService::pendingObligations($employee);
        $parts = array_filter([
            $pending['loans'] > 0 ? "{$pending['loans']} préstamo(s) aprobado(s)" : null,
            $pending['advances'] > 0 ? "{$pending['advances']} adelanto(s) sin descontar" : null,
        ]);

        return $parts === [] ? '' : 'Atención: tiene '.implode(' y ', $parts).'. La baja no los cancela ni los descuenta: revisalos antes.';
    }
}
