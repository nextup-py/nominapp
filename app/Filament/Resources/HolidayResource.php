<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\HolidayResource\Pages;
use App\Models\AttendanceDay;
use App\Models\Holiday;
use App\Services\HolidayWorkforce;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HolidayResource extends Resource
{
    use UsesNavigationLayout;

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

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Holiday::getTypeOptions())
                            ->default(Holiday::TYPE_FIXED)
                            ->native(false)
                            ->required()
                            ->helperText('Los fijos se copian solos al año siguiente; los móviles y los días no laborables hay que cargarlos a mano cada año.')
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

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Holiday::getTypeLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => Holiday::getTypeColors()[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('worked')
                    ->label('Trabajaron')
                    ->state(fn (Holiday $record): ?int => $record->date->lte(today())
                        ? AttendanceDay::whereDate('date', $record->date)->whereNotNull('check_in_time')->count()
                        : null)
                    ->placeholder('—')
                    ->tooltip('Empleados con marcación ese día (solo fechas ya pasadas)'),

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
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Holiday::getTypeOptions()),

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
                Action::make('who_works')
                    ->label('Quién trabaja')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->modalHeading(fn (Holiday $record): string => $record->name.' — '.$record->date->format('d/m/Y'))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Holiday $record) => view('filament.holidays.workforce', ['data' => HolidayWorkforce::forDate($record->date)])),
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
