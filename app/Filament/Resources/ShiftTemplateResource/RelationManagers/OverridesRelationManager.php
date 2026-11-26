<?php

namespace App\Filament\Resources\ShiftTemplateResource\RelationManagers;

use App\Models\ShiftOverride;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Turnos puntuales del Planificador que usan este turno (empleado y fecha), de solo lectura. */
class OverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'overrides';

    protected static ?string $title = 'Turnos puntuales';

    protected static ?string $modelLabel = 'turno puntual';

    protected static ?string $pluralModelLabel = 'turnos puntuales';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('override_date')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'createdBy']))
            ->columns([
                TextColumn::make('override_date')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->getStateUsing(fn (ShiftOverride $record): string => trim(($record->employee?->first_name ?? '').' '.($record->employee?->last_name ?? '')) ?: 'Empleado eliminado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('ci', 'like', "%{$search}%")
                    )),
                TextColumn::make('reason_type')
                    ->label('Motivo')
                    ->formatStateUsing(fn ($state) => ShiftOverride::getReasonTypeLabels()[$state] ?? $state)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('createdBy.name')->label('Registrado por')->placeholder('Sin dato'),

                TextColumn::make('notes')->label('Notas')->placeholder('Sin notas')->wrap(),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label('Solo de hoy en adelante')
                    ->query(fn (Builder $query) => $query->where('override_date', '>=', today()->toDateString())),
            ])
            ->actions([
                Action::make('history')
                    ->label('Historial')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading('Historial del turno puntual')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (ShiftOverride $record) => view('filament.shift-overrides.history', [
                        'audits' => $record->audits()->with('user')->latest()->get(),
                    ])),
            ])
            ->bulkActions([])
            ->defaultSort('override_date', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin turnos puntuales')
            ->emptyStateDescription('Ningún empleado tiene este turno asignado de forma puntual en el Planificador.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
