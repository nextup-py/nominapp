<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Settings\GeneralSettings;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/** Widget de tabla: contratos activos próximos a vencer o ya vencidos. Solo visible cuando existen. */
class ExpiringContracts extends BaseWidget
{
    protected static ?int $sort = 6;

    protected static ?string $heading = 'Contratos por Vencer';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $settings = app(GeneralSettings::class);
        $alertDays = $settings->contract_alert_days;

        return $table
            ->query(
                Contract::query()
                    ->with(['employee', 'position'])
                    ->where(function (Builder $query) use ($alertDays) {
                        // Contratos por vencer
                        $query->where('status', 'active')
                            ->whereNotNull('end_date')
                            ->where('end_date', '>', now())
                            ->where('end_date', '<=', now()->addDays($alertDays));
                    })
                    ->orWhere(function (Builder $query) {
                        // Contratos ya vencidos pero aún activos
                        $query->where('status', 'active')
                            ->whereNotNull('end_date')
                            ->where('end_date', '<', now());
                    })
                    ->orderBy('end_date')
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

                TextColumn::make('end_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->description(fn (Contract $record) => $record->expiration_description)
                    ->color(fn (Contract $record) => $record->isExpired() ? 'danger' : 'warning'),

                TextColumn::make('salary')
                    ->label('Salario')
                    ->money('PYG', locale: 'es_PY'),
            ])
            ->emptyStateHeading('Sin contratos por vencer')
            ->emptyStateDescription("No hay contratos que venzan en los próximos {$alertDays} días")
            ->emptyStateIcon('heroicon-o-check-circle')
            ->striped()
            ->paginated(false);
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
            ->where(function ($query) use ($alertDays) {
                $query->where('end_date', '<=', now()->addDays($alertDays))
                    ->orWhere('end_date', '<', now());
            })
            ->exists();
    }
}
