<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Períodos de nómina de la empresa — solo lectura. Se crean y gestionan desde el módulo Períodos de Nómina.
 */
class PayrollPeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'payrollPeriods';

    protected static ?string $title = 'Períodos de nómina';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Define la tabla de períodos de nómina con su frecuencia, vigencia, estado y cantidad de recibos.
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('payrolls'))
            ->recordUrl(fn (PayrollPeriod $record) => PayrollPeriodResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Período')
                    ->icon('heroicon-o-calendar-days')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('frequency')
                    ->label('Frecuencia')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => PayrollPeriod::frequencyOptions()[$state] ?? $state),

                TextColumn::make('start_date')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('payrolls_count')
                    ->label('Recibos')
                    ->badge()
                    ->color('info')
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PayrollPeriod::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state) => PayrollPeriod::statusColors()[$state] ?? 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PayrollPeriod::statusOptions())
                    ->native(false),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('start_date', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin períodos de nómina')
            ->emptyStateDescription('Los períodos de nómina de esta empresa se crean desde Nómina → Períodos de Nómina.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
