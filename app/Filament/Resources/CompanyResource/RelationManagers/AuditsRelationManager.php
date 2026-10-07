<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * RelationManager de historial de cambios para el módulo de Empresas.
 *
 * Muestra eventos de auditoría con etiquetas en español y formatos de campo legibles.
 */
class AuditsRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Historial de cambios';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /** Traduce el nombre del evento de Eloquent a español. */
    private function translateEvent(string $event): string
    {
        return match ($event) {
            'created' => 'Creado',
            'updated' => 'Modificado',
            'deleted' => 'Eliminado',
            'restored' => 'Restaurado',
            default => ucfirst($event),
        };
    }

    /** Devuelve el color semántico para cada tipo de evento. */
    private function eventColor(string $event): string
    {
        return match ($event) {
            'created' => 'success',
            'updated' => 'info',
            'deleted' => 'danger',
            'restored' => 'warning',
            default => 'gray',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->orderBy('created_at', 'desc'))
            ->emptyStateHeading('Sin historial de cambios')
            ->emptyStateDescription('Las modificaciones al registro de la empresa aparecerán aquí.')
            ->emptyStateIcon('heroicon-o-clock')
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->placeholder('Sistema')
                    ->searchable(),

                Tables\Columns\TextColumn::make('event')
                    ->label('Evento')
                    ->formatStateUsing(fn (string $state) => $this->translateEvent($state))
                    ->badge()
                    ->color(fn (string $state) => $this->eventColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('old_values')
                    ->label('Valores anteriores')
                    ->formatStateUsing(fn (Column $column, $record) => $this->getOwnerRecord()->formatAuditFieldsForPresentation($column->getName(), $record))
                    ->html()
                    ->wrap(),

                Tables\Columns\TextColumn::make('new_values')
                    ->label('Nuevos valores')
                    ->formatStateUsing(fn (Column $column, $record) => $this->getOwnerRecord()->formatAuditFieldsForPresentation($column->getName(), $record))
                    ->html()
                    ->wrap(),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
