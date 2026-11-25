<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RotationPatternResource\Pages;
use App\Filament\Resources\RotationPatternResource\RelationManagers;
use App\Models\Company;
use App\Models\RotationPattern;
use App\Models\ShiftTemplate;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** Recurso Filament para gestionar los patrones de rotación de turnos. */
class RotationPatternResource extends Resource
{
    protected static ?string $model = RotationPattern::class;

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationLabel = 'Patrones de rotación';

    protected static ?string $modelLabel = 'Patrón de Rotación';

    protected static ?string $pluralModelLabel = 'Patrones de Rotación';

    protected static ?string $slug = 'patrones-rotacion';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Define el formulario de creación y edición de un patrón de rotación.
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
                            ->required()
                            ->live(),

                        TextInput::make('name')
                            ->label('Nombre del patrón')
                            ->placeholder('Ej: 3 Turnos Rotativos 21 días')
                            ->required()
                            ->maxLength(60),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->placeholder('Ej: Ciclo de 21 días con 3 turnos de 7 días cada uno y 1 franco semanal.')
                            ->maxLength(150)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Secuencia del ciclo')
                    ->icon('heroicon-o-queue-list')
                    ->description('Definí el orden de los turnos día a día. El ciclo se repite automáticamente.')
                    ->schema([
                        Repeater::make('sequence')
                            ->label('')
                            ->helperText('Cada ítem representa un día del ciclo en orden: ítem 1 = Día 1, ítem 2 = Día 2, etc. Arrastrá para reordenar.')
                            ->schema([
                                Select::make('shift_template_id')
                                    ->label('Turno')
                                    ->options(function (Get $get) {
                                        $companyId = $get('../../company_id');

                                        return ShiftTemplate::query()
                                            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                            ->where('is_active', true)
                                            ->orderByRaw('is_day_off ASC, name ASC')
                                            ->get()
                                            ->mapWithKeys(fn ($s) => [
                                                $s->id => $s->is_day_off
                                                    ? $s->name
                                                    : "{$s->name} ({$s->start_time} – {$s->end_time})",
                                            ]);
                                    })
                                    ->native(false)
                                    ->searchable()
                                    ->required(),
                            ])
                            ->addActionLabel('Agregar día al ciclo')
                            ->reorderable()
                            ->reorderableWithDragAndDrop()
                            ->collapsible()
                            ->minItems(1)
                            ->defaultItems(7)
                            ->itemLabel(fn (array $state) => filled($state['shift_template_id'] ?? null)
                                    ? (ShiftTemplate::find($state['shift_template_id'])?->name ?? 'Turno')
                                    : 'Turno'
                            )
                            ->mutateDehydratedStateUsing(
                                // Convertir [{shift_template_id: X}, ...] → [X, ...]
                                fn (array $state) => array_values(
                                    array_map(fn ($item) => (int) $item['shift_template_id'], $state)
                                )
                            ),
                    ]),
            ]);
    }

    /**
     * Transforma la secuencia plana [id1, id2, ...] al formato de items del Repeater
     * [{shift_template_id: id1}, {shift_template_id: id2}, ...] al editar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['sequence']) && is_array($data['sequence'])) {
            $data['sequence'] = array_map(
                fn ($id) => ['shift_template_id' => $id],
                $data['sequence']
            );
        }

        return $data;
    }

    /**
     * Define la tabla de listado de patrones de rotación.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Patrón')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->sortable(),

                TextColumn::make('cycle_length')
                    ->label('Días en ciclo')
                    ->state(fn (RotationPattern $record) => $record->cycle_length)
                    ->suffix(' días')
                    ->badge()
                    ->color('info'),

                TextColumn::make('assignments_count')
                    ->label('Empleados activos')
                    ->counts([
                        'assignments' => fn ($q) => $q->whereNull('valid_until')
                            ->orWhere('valid_until', '>=', today()),
                    ])
                    ->badge()
                    ->color('success'),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->placeholder('—')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(Company::orderBy('name')->pluck('name', 'id'))
                    ->native(false),

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
                    ->label('Desactivar')
                    ->modalHeading('Desactivar patrón')
                    ->modalDescription('Los empleados con este patrón activo dejarán de tener turno calculado. ¿Continuar?')
                    ->modalSubmitActionLabel('Sí, desactivar')
                    ->action(fn (RotationPattern $record) => $record->update(['is_active' => false]))
                    ->successNotificationTitle('Patrón desactivado'),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\EmployeesRelationManager::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRotationPatterns::route('/'),
            'create' => Pages\CreateRotationPattern::route('/create'),
            'edit' => Pages\EditRotationPattern::route('/{record}/edit'),
        ];
    }
}
