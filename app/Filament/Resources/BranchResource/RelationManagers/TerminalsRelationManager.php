<?php

namespace App\Filament\Resources\BranchResource\RelationManagers;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Terminales de marcación de la sucursal — solo lectura. Crear, editar y
 * las acciones de ciclo de vida (activar, revocar token, etc.) se hacen
 * desde el módulo Terminales.
 */
class TerminalsRelationManager extends RelationManager
{
    protected static string $relationship = 'terminals';

    protected static ?string $title = 'Terminales';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSyncTokenFlag())
            ->recordUrl(fn (Terminal $record) => TerminalResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->icon('heroicon-o-computer-desktop')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Código')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Sin terminales en esta sucursal')
            ->emptyStateDescription('Los terminales de marcación asignados a esta sucursal aparecerán acá.')
            ->emptyStateIcon('heroicon-o-computer-desktop');
    }
}
