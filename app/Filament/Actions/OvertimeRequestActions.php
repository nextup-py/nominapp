<?php

namespace App\Filament\Actions;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\OvertimeRequestService;
use App\Services\TeamScopeService;
use DomainException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;

/**
 * Acciones del pedido previo de horas extra (pedir, aprobar, rechazar, cancelar). Se construyen con la clase de
 * acción de tabla o de página (mismo patrón que `AbsenceActions`); el registro llega por inyección de `$record`.
 * Cada una valida el permiso con `OvertimeRequestService` y avisa el resultado.
 */
class OvertimeRequestActions
{
    /**
     * Empleados que el usuario puede elegir al pedir horas extra: todos los activos para RR.HH.; solo su equipo para un jefe.
     *
     * @return array<int, string>
     */
    public static function employeeOptions(User $user): array
    {
        $query = Employee::query()->active()->orderBy('first_name')->orderBy('last_name');

        if (! $user->can(OvertimeRequestService::HR_CREATE)) {
            $query->whereIn('id', app(TeamScopeService::class)->employeeIds($user));
        }

        return $query->get()->mapWithKeys(fn (Employee $e): array => [$e->id => "{$e->first_name} {$e->last_name} (CI: {$e->ci})"])->all();
    }

    /**
     * Campos del formulario de un pedido.
     *
     * @return array<int, mixed>
     */
    public static function formSchema(): array
    {
        return [
            Select::make('employee_id')
                ->label('Empleado')
                ->options(fn (): array => self::employeeOptions(auth()->user()))
                ->searchable()
                ->required(),
            Grid::make(3)->schema([
                DatePicker::make('date_from')->label('Desde (fecha)')->native(false)->required()->default(today()),
                DatePicker::make('date_to')->label('Hasta (fecha)')->native(false)->helperText('Vacío: un solo día.'),
                TextInput::make('max_hours')->label('Horas máximas por día')->numeric()->step(0.25)->minValue(0.25)->required()->suffix('hrs'),
            ]),
            Grid::make(2)->schema([
                TimePicker::make('time_from')->label('Hora desde (opcional)')->seconds(false)->native(false),
                TimePicker::make('time_to')->label('Hora hasta (opcional)')->seconds(false)->native(false)
                    ->helperText('Si se indica, las horas extra se aprueban solas solo si la salida no pasa de esta hora.'),
            ]),
            Select::make('reason')
                ->label('Motivo')
                ->options(fn (): array => AttendanceEvent::getOvertimeReasonOptions())
                ->required(),
            Textarea::make('notes')->label('Detalle')->rows(2)->maxLength(500),
        ];
    }

    /**
     * «Pedir horas extra»: abre el formulario y crea el pedido con las reglas del servicio.
     *
     * @param  class-string  $class
     */
    public static function request(string $class): mixed
    {
        return $class::make('request_overtime')
            ->label('Pedir horas extra')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->visible(fn (): bool => auth()->user() !== null && (auth()->user()->can(OvertimeRequestService::HR_CREATE) || app(TeamScopeService::class)->employeeIds(auth()->user()) !== []))
            ->modalHeading('Pedir horas extra')
            ->modalDescription('El pedido autoriza horas extra anticipadas. Si lo pide un jefe, queda pendiente de RR.HH.; RR.HH. lo deja aprobado.')
            ->modalSubmitActionLabel('Enviar pedido')
            ->form(self::formSchema())
            ->action(function (array $data): void {
                try {
                    $request = app(OvertimeRequestService::class)->create(
                        auth()->user(),
                        Employee::findOrFail($data['employee_id']),
                        $data
                    );
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo crear el pedido')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title($request->status === OvertimeRequest::STATUS_APPROVED ? 'Pedido aprobado' : 'Pedido enviado a RR.HH.')
                    ->send();
            });
    }

    /**
     * «Aprobar»: el jefe (si el pedido lo espera a él) o RR.HH.
     *
     * @param  class-string  $class
     */
    public static function approve(string $class): mixed
    {
        return $class::make('approve_overtime_request')
            ->label('Aprobar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (OvertimeRequest $record): bool => self::canApprove($record))
            ->requiresConfirmation()
            ->modalHeading('Aprobar pedido de horas extra')
            ->modalDescription(fn (OvertimeRequest $record): string => $record->status === OvertimeRequest::STATUS_PENDING_BOSS && ! app(OvertimeRequestService::class)->isHr(auth()->user())
                ? 'El pedido pasará a RR.HH. para su aprobación final.'
                : 'Las horas extra de esas fechas quedarán autorizadas y se aprobarán solas hasta el tope pedido.')
            ->modalSubmitActionLabel('Sí, aprobar')
            ->action(function (OvertimeRequest $record): void {
                $service = app(OvertimeRequestService::class);
                $user = auth()->user();

                try {
                    $service->isHr($user) ? $service->hrApprove($user, $record) : $service->bossApprove($user, $record);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo aprobar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pedido aprobado')->send();
            });
    }

    /**
     * «Rechazar»: pide el motivo y avisa a quien lo pidió.
     *
     * @param  class-string  $class
     */
    public static function reject(string $class): mixed
    {
        return $class::make('reject_overtime_request')
            ->label('Rechazar')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (OvertimeRequest $record): bool => self::canApprove($record))
            ->modalHeading('Rechazar pedido de horas extra')
            ->modalSubmitActionLabel('Sí, rechazar')
            ->form([Textarea::make('reason')->label('Motivo del rechazo')->required()->maxLength(300)])
            ->action(function (OvertimeRequest $record, array $data): void {
                try {
                    app(OvertimeRequestService::class)->reject(auth()->user(), $record, $data['reason']);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo rechazar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pedido rechazado')->send();
            });
    }

    /**
     * «Cancelar»: solo si nadie lo usó todavía.
     *
     * @param  class-string  $class
     */
    public static function cancel(string $class): mixed
    {
        return $class::make('cancel_overtime_request')
            ->label('Cancelar')
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->visible(fn (OvertimeRequest $record): bool => auth()->user() !== null
                && app(OvertimeRequestService::class)->cancellationBlocker(auth()->user(), $record) === null)
            ->requiresConfirmation()
            ->modalHeading('Cancelar pedido de horas extra')
            ->modalDescription('El pedido deja de autorizar horas extra.')
            ->modalSubmitActionLabel('Sí, cancelar')
            ->action(function (OvertimeRequest $record): void {
                try {
                    app(OvertimeRequestService::class)->cancel(auth()->user(), $record);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('No se pudo cancelar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pedido cancelado')->send();
            });
    }

    /** Indica si el usuario actual puede aprobar o rechazar el pedido (como jefe o como RR.HH.). */
    private static function canApprove(OvertimeRequest $record): bool
    {
        $user = auth()->user();
        $service = app(OvertimeRequestService::class);

        return $user !== null && $record->isPending() && ($service->canDecideAsBoss($user, $record) || $service->isHr($user));
    }
}
