<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HolidayResource\Pages;
use App\Models\Holiday;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static ?string $navigationGroup = 'Catálogos';

    protected static ?string $navigationLabel = 'Feriados';

    protected static ?string $label = 'Feriado';

    protected static ?string $pluralLabel = 'Feriados';

    protected static ?string $slug = 'feriados';

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Feriado')
                    ->schema([
                        DatePicker::make('date')
                            ->label('Fecha')
                            ->placeholder('Seleccione la fecha del feriado')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->columnSpan(1),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ejemplo: Día de la Independencia')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-o-calendar')
                    ->iconColor('primary'),

                TextColumn::make('name')
                    ->label('Nombre del Feriado')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('day_of_week')
                    ->label('Día')
                    ->getStateUsing(fn ($record) => ucfirst(Carbon::parse($record->date)->locale('es')->dayName))
                    ->badge()
                    ->color(fn ($record) => Carbon::parse($record->date)->isWeekend() ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('current_year')
                    ->label('Año Actual')
                    ->query(fn (Builder $query) => $query->whereYear('date', now()->year)),

                Filter::make('next_year')
                    ->label('Próximo Año')
                    ->query(fn (Builder $query) => $query->whereYear('date', now()->addYear()->year)),

                Filter::make('upcoming')
                    ->label('Próximos Feriados')
                    ->query(fn (Builder $query) => $query->where('date', '>=', now()->startOfDay())),
            ])
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar feriado?')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date', 'asc')
            ->emptyStateHeading('No hay feriados registrados')
            ->emptyStateDescription('Comienza a agregar los feriados nacionales para el cálculo de asistencia y nómina.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageHolidays::route('/'),
        ];
    }
}
