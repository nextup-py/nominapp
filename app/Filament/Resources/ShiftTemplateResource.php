<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\ShiftTemplateResource\Pages;
use App\Models\Company;
use App\Models\ShiftTemplate;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Recurso Filament para gestionar los turnos de trabajo reutilizables. */
class ShiftTemplateResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = ShiftTemplate::class;

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationLabel = 'Plantillas de turno';

    protected static ?string $modelLabel = 'Turno';

    protected static ?string $pluralModelLabel = 'Turnos';

    protected static ?string $slug = 'turnos';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Define el formulario de creación y edición de un turno.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identificación')
                    ->icon('heroicon-o-tag')
                    ->columns(2)
                    ->schema([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(Company::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        TextInput::make('name')
                            ->label('Nombre del turno')
                            ->placeholder('Ej: Turno Mañana')
                            ->required()
                            ->maxLength(60),

                        Select::make('shift_type')
                            ->label('Tipo de jornada')
                            ->options(ShiftTemplate::getShiftTypeOptions())
                            ->native(false)
                            ->required(),

                        ColorPicker::make('color')
                            ->label('Color en el planner')
                            ->default('#6B7280')
                            ->required(),

                        TextInput::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones opcionales')
                            ->maxLength(100)
                            ->columnSpanFull(),
                    ]),

                Section::make('Horario')
                    ->icon('heroicon-o-clock')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_day_off')
                            ->label('Es día libre (Franco)')
                            ->live()
                            ->helperText('Activar si este turno representa un día de descanso.')
                            ->columnSpanFull(),

                        TimePicker::make('start_time')
                            ->label('Hora de entrada')
                            ->seconds(false)
                            ->native(false)
                            ->required(fn (Get $get) => ! $get('is_day_off'))
                            ->visible(fn (Get $get) => ! $get('is_day_off')),

                        TimePicker::make('end_time')
                            ->label('Hora de salida')
                            ->seconds(false)
                            ->native(false)
                            ->required(fn (Get $get) => ! $get('is_day_off'))
                            ->visible(fn (Get $get) => ! $get('is_day_off'))
                            ->helperText('Si es menor que la entrada, el turno cruza la medianoche.'),

                        TextInput::make('break_minutes')
                            ->label('Minutos de descanso')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(480)
                            ->default(0)
                            ->suffix('min')
                            ->visible(fn (Get $get) => ! $get('is_day_off'))
                            ->helperText('Tiempo de descanso descontado del tiempo neto trabajado.'),
                    ]),
            ]);
    }

    /**
     * Define la tabla de listado de turnos.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')
                    ->label('')
                    ->width('40px'),

                TextColumn::make('name')
                    ->label('Turno')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('shift_type')
                    ->label('Jornada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ShiftTemplate::getShiftTypeLabels()[$state] ?? $state)
                    ->color(fn ($state) => ShiftTemplate::getShiftTypeColors()[$state] ?? 'gray'),

                IconColumn::make('is_day_off')
                    ->label('Franco')
                    ->boolean()
                    ->trueIcon('heroicon-o-moon')
                    ->falseIcon('heroicon-o-sun')
                    ->trueColor('info')
                    ->falseColor('success'),

                TextColumn::make('start_time')
                    ->label('Entrada')
                    ->placeholder('—')
                    ->time('H:i'),

                TextColumn::make('end_time')
                    ->label('Salida')
                    ->placeholder('—')
                    ->time('H:i'),

                TextColumn::make('break_minutes')
                    ->label('Descanso')
                    ->suffix(' min')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(Company::orderBy('name')->pluck('name', 'id'))
                    ->native(false),

                SelectFilter::make('shift_type')
                    ->label('Jornada')
                    ->options(ShiftTemplate::getShiftTypeOptions())
                    ->native(false),

                TernaryFilter::make('is_day_off')
                    ->label('Tipo')
                    ->trueLabel('Solo francos')
                    ->falseLabel('Solo turnos activos'),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->default(true),
            ])
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary'),
                DeleteAction::make()
                    ->modalHeading('Desactivar turno')
                    ->modalDescription('Este turno puede estar en uso por patrones de rotación activos. ¿Deseas desactivarlo?')
                    ->modalSubmitActionLabel('Sí, desactivar')
                    ->action(fn (ShiftTemplate $record) => $record->update(['is_active' => false]))
                    ->successNotificationTitle('Turno desactivado'),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShiftTemplates::route('/'),
            'create' => Pages\CreateShiftTemplate::route('/create'),
            'edit' => Pages\EditShiftTemplate::route('/{record}/edit'),
        ];
    }
}
