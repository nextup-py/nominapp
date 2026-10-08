<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Filament\Resources\TerminalResource;
use App\Models\Branch;
use App\Models\Terminal;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Terminales de marcación de todas las sucursales de la empresa — solo lectura. Crear, editar y las
 * acciones de ciclo de vida (vincular, comandos remotos, etc.) se hacen desde el módulo Terminales.
 */
class TerminalsRelationManager extends RelationManager
{
    protected static string $relationship = 'terminals';

    protected static ?string $title = 'Terminales';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Define la tabla de terminales con estado y conectividad (con el flag de token precargado: sin N+1).
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSyncTokenFlag()->with('branch'))
            ->recordUrl(fn (Terminal $record) => TerminalResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->icon('heroicon-o-computer-desktop')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('Sin sucursal')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Terminal::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getStatusColors()[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('connectivity_status')
                    ->label('Conectividad')
                    ->badge()
                    ->tooltip('Sin vincular: sin token de sincronización vigente. Desconectado: sin heartbeat dentro del umbral (propio o general). Fuera de horario: desconectado pero fuera de su horario de vigilancia')
                    ->formatStateUsing(fn (string $state) => Terminal::getConnectivityStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getConnectivityStatusColors()[$state] ?? 'gray'),

                TextColumn::make('last_heartbeat_at')
                    ->label('Último heartbeat')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->options(fn () => Branch::where('company_id', $this->ownerRecord->id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Terminal::getStatusLabels())
                    ->native(false),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin terminales en esta empresa')
            ->emptyStateDescription('Los terminales de marcación de sus sucursales aparecerán acá.')
            ->emptyStateIcon('heroicon-o-computer-desktop');
    }
}
