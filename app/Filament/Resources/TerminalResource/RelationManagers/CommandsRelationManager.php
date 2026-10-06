<?php

namespace App\Filament\Resources\TerminalResource\RelationManagers;

use App\Models\TerminalCommand;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Historial de solo lectura de los comandos remotos enviados al terminal y su resultado. */
class CommandsRelationManager extends RelationManager
{
    protected static string $relationship = 'commands';

    protected static ?string $title = 'Comandos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Enviado')->dateTime('d/m/Y H:i:s')->sortable(),

                TextColumn::make('command')
                    ->label('Comando')
                    ->formatStateUsing(fn (string $state) => TerminalCommand::getCommandLabels()[$state] ?? $state),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TerminalCommand::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => TerminalCommand::getStatusColors()[$state] ?? 'gray'),

                TextColumn::make('requestedBy.name')->label('Enviado por')->placeholder('Sistema'),

                TextColumn::make('completed_at')->label('Resuelto')->dateTime('d/m/Y H:i:s')->placeholder('Sin resolver'),

                TextColumn::make('result_message')->label('Detalle')->placeholder('Sin detalle')->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(TerminalCommand::getStatusLabels())->native(false),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('requestedBy'))
            ->actions([])
            ->bulkActions([])
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin comandos enviados')
            ->emptyStateDescription('Los comandos remotos que se envíen a este terminal y su resultado quedan registrados acá.')
            ->emptyStateIcon('heroicon-o-command-line');
    }
}
