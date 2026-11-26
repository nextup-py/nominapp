<?php

namespace App\Filament\Resources\ShiftTemplateResource\RelationManagers;

use App\Filament\Resources\RotationPatternResource;
use App\Models\RotationPattern;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Patrones de rotación cuyo ciclo incluye este turno, de solo lectura. */
class RotationPatternsRelationManager extends RelationManager
{
    protected static string $relationship = 'rotationPatterns';

    protected static ?string $title = 'Patrones de rotación';

    protected static ?string $modelLabel = 'patrón';

    protected static ?string $pluralModelLabel = 'patrones';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->recordUrl(fn (RotationPattern $record) => RotationPatternResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Patrón')->icon('heroicon-o-arrow-path')->searchable()->sortable()->weight('medium'),
                TextColumn::make('_days_in_cycle')
                    ->label('Días en el ciclo con este turno')
                    ->getStateUsing(fn (RotationPattern $record): int => collect($record->sequence ?? [])
                        ->filter(fn ($id) => (string) $id === (string) $this->getOwnerRecord()->id)->count()),
                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Ningún patrón lo usa')
            ->emptyStateDescription('Este turno no forma parte del ciclo de ningún patrón de rotación.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }
}
