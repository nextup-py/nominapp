<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\AguinaldoPeriodResource;
use App\Models\AguinaldoPeriod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Períodos de aguinaldo de la empresa (uno por año) — solo lectura. Se gestionan desde el módulo Aguinaldo.
 */
class AguinaldoPeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'aguinaldoPeriods';

    protected static ?string $title = 'Aguinaldos';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Define la tabla de períodos de aguinaldo con generados, pagados y estado.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount('aguinaldos')
                ->withCount(['aguinaldos as aguinaldos_paid_count' => fn ($q) => $q->where('status', 'paid')])
            )
            ->recordUrl(fn (AguinaldoPeriod $record) => AguinaldoPeriodResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('year')
                    ->label('Año')
                    ->icon('heroicon-o-gift')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('aguinaldos_count')
                    ->label('Generados')
                    ->badge()
                    ->color('success')
                    ->alignCenter(),

                TextColumn::make('aguinaldos_paid_count')
                    ->label('Pagados')
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => AguinaldoPeriod::getStatusLabel($state))
                    ->color(fn (string $state) => AguinaldoPeriod::getStatusColor($state))
                    ->icon(fn (string $state) => AguinaldoPeriod::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('closed_at')
                    ->label('Cerrado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin cerrar')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AguinaldoPeriod::getStatusOptions())
                    ->native(false),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('year', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin períodos de aguinaldo')
            ->emptyStateDescription('Los aguinaldos de esta empresa se gestionan desde Nómina → Aguinaldo.')
            ->emptyStateIcon('heroicon-o-gift');
    }
}
