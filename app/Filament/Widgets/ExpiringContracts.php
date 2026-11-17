<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\ContractRenewalForm;
use App\Filament\Actions\EmployeeTerminationAction;
use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Services\ContractService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/** Widget de tabla: contratos activos próximos a vencer o ya vencidos. Solo visible cuando existen. */
class ExpiringContracts extends BaseWidget
{
    protected static ?int $sort = 6;

    protected static ?string $heading = 'Contratos por vencer y fines de prueba';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $settings = app(GeneralSettings::class);
        $alertDays = $settings->contract_alert_days;

        $trialIds = Contract::trialEndingWithin($settings->contract_trial_alert_days)->pluck('id')->all();

        return $table
            ->query(
                Contract::query()
                    ->with(['employee', 'position'])
                    ->where('status', 'active')
                    ->where(function (Builder $query) use ($alertDays, $trialIds) {
                        $query->where(fn (Builder $q) => $q->whereNotNull('end_date')->where('end_date', '<=', now()->addDays($alertDays)))
                            ->orWhereIn('id', $trialIds);
                    })
                    ->orderByRaw('COALESCE(end_date, start_date)')
            )
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->wrap(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contract::getTypeLabel($state))
                    ->color(fn (string $state): string => Contract::getTypeColor($state)),

                TextColumn::make('position.name')
                    ->label('Cargo')
                    ->wrap(),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->badge()
                    ->getStateUsing(fn (Contract $record): string => implode(' · ', self::reasons($record, $this->trialAlertDays())))
                    ->color(fn (Contract $record): string => $record->isExpired() ? 'danger' : 'warning'),

                TextColumn::make('end_date')
                    ->label('Fecha')
                    ->getStateUsing(fn (Contract $record) => ($record->end_date !== null && $record->end_date->lte(now()->addDays(app(GeneralSettings::class)->contract_alert_days)) ? $record->end_date : $record->trial_end_date)?->format('d/m/Y'))
                    ->description(fn (Contract $record) => $record->expiration_description),

                TextColumn::make('salary')
                    ->label('Salario')
                    ->money('PYG', locale: 'es_PY'),
            ])
            ->actions([
                Action::make('renew')
                    ->label('Renovar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (Contract $record): bool => $record->canBeRenewed() && (auth()->user()?->can('renew_contract') ?? false))
                    ->modalHeading('Renovar contrato')
                    ->modalSubmitActionLabel('Sí, renovar')
                    ->form(fn (Contract $record) => ContractRenewalForm::fields($record))
                    ->action(function (Contract $record, array $data): void {
                        ContractService::renew($record, $data);

                        Notification::make()->success()->title('Contrato renovado')->body("Se creó un nuevo contrato para {$record->employee->full_name}.")->send();
                    }),
                EmployeeTerminationAction::make(Action::class),
            ])
            ->emptyStateHeading('Sin contratos por vencer')
            ->emptyStateDescription("No hay contratos que venzan en los próximos {$alertDays} días")
            ->emptyStateIcon('heroicon-o-check-circle')
            ->striped()
            ->paginated(false);
    }

    /** Días de aviso de fin de prueba configurados. */
    private function trialAlertDays(): int
    {
        return app(GeneralSettings::class)->contract_trial_alert_days;
    }

    /**
     * Motivos por los que el contrato aparece: vencimiento (o ya vencido) y/o fin de prueba próximo.
     *
     * @return array<int, string>
     */
    private static function reasons(Contract $record, int $trialDays): array
    {
        $reasons = [];

        if ($record->end_date !== null) {
            $reasons[] = $record->isExpired() ? 'Vencido' : 'Por vencer';
        }

        $trialEnd = $record->trial_end_date;

        if ($trialDays > 0 && $trialEnd !== null && $trialEnd->between(today(), today()->addDays($trialDays))) {
            $reasons[] = 'Fin de prueba';
        }

        return $reasons ?: ['Por vencer'];
    }

    /**
     * Solo muestra el widget cuando hay contratos por vencer o ya vencidos.
     */
    public static function canView(): bool
    {
        if (! ContractResource::canViewAny()) {
            return false;
        }

        $settings = app(GeneralSettings::class);
        $alertDays = $settings->contract_alert_days;

        return Contract::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addDays($alertDays))
            ->exists()
            || ($settings->contract_trial_alert_days > 0 && Contract::trialEndingWithin($settings->contract_trial_alert_days)->isNotEmpty());
    }
}
