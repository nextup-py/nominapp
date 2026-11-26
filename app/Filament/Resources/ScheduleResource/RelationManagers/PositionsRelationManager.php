<?php

namespace App\Filament\Resources\ScheduleResource\RelationManagers;

use App\Filament\Resources\PositionResource;
use App\Models\Position;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cargos que tienen este horario como sugerido (`positions.default_schedule_id`), de solo lectura. El horario sugerido
 * solo preselecciona la acción **Asignar horario**; no asigna nada solo. Sirve para saber qué cargos se ven afectados
 * antes de cambiar o desactivar el horario.
 */
class PositionsRelationManager extends RelationManager
{
    protected static string $relationship = 'positions';

    protected static ?string $title = 'Cargos con este horario';

    protected static ?string $modelLabel = 'cargo';

    protected static ?string $pluralModelLabel = 'cargos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->recordUrl(fn (Position $record) => PositionResource::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('department.company'))
            ->columns([
                TextColumn::make('name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('department.name')
                    ->label('Departamento')
                    ->icon('heroicon-o-building-library')
                    ->placeholder('Sin departamento'),

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
            ->emptyStateHeading('Ningún cargo lo sugiere')
            ->emptyStateDescription('Ningún cargo tiene este horario como horario sugerido para asignar.')
            ->emptyStateIcon('heroicon-o-briefcase');
    }
}
