<?php

namespace App\Filament\Resources\TerminalResource\RelationManagers;

use App\Models\TerminalEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bitácora de solo lectura del terminal: quién abrió ventanas, generó enlaces,
 * aprobó o desvinculó. Solo transiciones, nunca un evento por heartbeat.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Bitácora';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Evento')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TerminalEvent::getTypeLabels()[$state] ?? $state)
                    ->color(fn (string $state) => TerminalEvent::getTypeColors()[$state] ?? 'gray'),

                TextColumn::make('actor.name')
                    ->label('Usuario')
                    ->placeholder('Dispositivo o sistema'),

                TextColumn::make('detail')
                    ->label('Detalle')
                    ->placeholder('Sin detalle')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Evento')
                    ->options(TerminalEvent::getTypeLabels())
                    ->native(false),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin eventos registrados')
            ->emptyStateDescription('Las vinculaciones, ventanas, enlaces y desvinculaciones de este terminal quedan registrados acá.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
