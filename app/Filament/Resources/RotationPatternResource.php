<?php

namespace App\Filament\Resources;

use App\Filament\Actions\RotationPatternStatusAction;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\RotationPatternResource\Pages;
use App\Filament\Resources\RotationPatternResource\RelationManagers;
use App\Models\Company;
use App\Models\RotationPattern;
use App\Models\ShiftTemplate;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Recurso Filament para gestionar los patrones de rotación de turnos. */
class RotationPatternResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = RotationPattern::class;

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationLabel = 'Patrones de rotación';

    protected static ?string $modelLabel = 'Patrón de Rotación';

    protected static ?string $pluralModelLabel = 'Patrones de Rotación';

    protected static ?string $slug = 'patrones-rotacion';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    /** Hay más de una empresa activa: solo entonces se muestra y se pide la empresa del patrón. */
    public static function multipleCompanies(): bool
    {
        return Company::active()->count() > 1;
    }

    /** Quien puede crear, editar, asignar y quitar (las acciones personalizadas no consultan la policy). */
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('update_rotation_pattern');
    }

    /**
     * Detalle de cada patrón en la búsqueda global: largo del ciclo y, si corresponde, que está inactivo.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Ciclo' => $record->cycle_length.' '.($record->cycle_length === 1 ? 'día' : 'días'),
            'Estado' => $record->is_active ? null : 'Inactivo',
        ]);
    }

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
                        Placeholder::make('affected_employees')
                            ->label('Empleados afectados')
                            ->content(fn (?RotationPattern $record): string => ($count = $record?->currentAssignmentsCount() ?? 0).' '.($count === 1 ? 'empleado tiene' : 'empleados tienen').' asignado este patrón: los cambios del ciclo rigen desde hoy. Las jornadas ya calculadas no cambian hasta recalcularlas.')
                            ->visible(fn (?RotationPattern $record): bool => ($record?->currentAssignmentsCount() ?? 0) > 0)
                            ->columnSpanFull(),

                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(fn (?RotationPattern $record) => Company::query()
                                ->where(fn (Builder $query) => $query->where('is_active', true)->when($record?->company_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn () => Company::active()->count() === 1 ? Company::active()->value('id') : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->disabled(fn (?RotationPattern $record): bool => $record !== null && $record->deletionBlockers() !== [])
                            ->dehydrated()
                            ->helperText(fn (?RotationPattern $record): ?string => $record !== null && $record->deletionBlockers() !== [] ? 'No se puede cambiar mientras el patrón tenga asignaciones.' : null)
                            ->visible(fn (): bool => static::multipleCompanies() || ! Company::active()->exists()),

                        TextInput::make('name')
                            ->label('Nombre del patrón')
                            ->placeholder('Ej: 3 Turnos Rotativos 21 días')
                            ->required()
                            ->maxLength(60)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('company_id', $get('company_id') ?: Company::active()->value('id')),
                            )
                            ->validationMessages(['unique' => 'Ya existe un patrón con ese nombre en esta empresa. Usar uno distinto para no confundirlos.']),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->placeholder('Ej: Ciclo de 21 días con 3 turnos de 7 días cada uno y 1 franco semanal.')
                            ->maxLength(150)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Secuencia del ciclo')
                    ->icon('heroicon-o-queue-list')
                    ->description('El orden de los turnos día a día. El ciclo se repite automáticamente.')
                    ->schema([
                        Repeater::make('sequence')
                            ->label('')
                            ->helperText('Cada ítem es un día del ciclo en orden: ítem 1 = Día 1, ítem 2 = Día 2, etc. Se pueden reordenar arrastrando.')
                            ->schema([
                                Select::make('shift_template_id')
                                    ->label('Turno')
                                    ->options(function (Get $get, $state) {
                                        $companyId = $get('../../company_id') ?: Company::active()->value('id');

                                        return ShiftTemplate::query()
                                            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                                            ->where(fn ($q) => $q->where('is_active', true)->when($state, fn ($q2) => $q2->orWhere('id', $state)))
                                            ->orderByRaw('is_day_off ASC, name ASC')
                                            ->get()
                                            ->mapWithKeys(fn (ShiftTemplate $s) => [
                                                $s->id => $s->name.($s->is_day_off ? '' : " ({$s->scheduleSummary()})").($s->is_active ? '' : ' - inactivo'),
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
                    ->icon('heroicon-o-arrow-path')
                    ->iconColor('primary')
                    ->weight('medium'),

                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->sortable()
                    ->visible(fn (): bool => static::multipleCompanies()),

                TextColumn::make('cycle_summary')
                    ->label('Ciclo')
                    ->getStateUsing(fn (RotationPattern $record, $livewire): string => $record->cycleSummary($livewire->shiftMap()))
                    ->wrap(),

                TextColumn::make('assignments_count')
                    ->label('Empleados vigentes')
                    ->counts([
                        'assignments' => fn ($q) => $q->where(fn ($q2) => $q2->whereNull('valid_until')->orWhere('valid_until', '>', today()->toDateString())),
                    ])
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'success' : 'gray'),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->placeholder('Sin descripción')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                    ->native(false)
                    ->visible(fn (): bool => static::multipleCompanies()),
            ])
            ->actions([
                TableAction::make('view_detail')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (RotationPattern $record): string => static::getUrl('view', ['record' => $record])),
                TableActionGroup::make([
                    TableAction::make('edit_pattern')
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary')
                        ->authorize(fn (RotationPattern $record): bool => (bool) auth()->user()?->can('update', $record))
                        ->url(fn (RotationPattern $record): string => static::getUrl('edit', ['record' => $record])),
                    static::duplicateAction(TableAction::class),
                    RotationPatternStatusAction::make(TableAction::class),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('deactivate_bulk')
                        ->label('Desactivar seleccionados')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->authorize(fn (): bool => static::canManage())
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar patrones')
                        ->modalDescription('Se omiten los que tienen empleados asignados vigentes o futuros.')
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->action(fn (Collection $records) => static::changeStatusBulk($records, false))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activate_bulk')
                        ->label('Activar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => static::canManage())
                        ->requiresConfirmation()
                        ->modalSubmitActionLabel('Sí, activar')
                        ->action(fn (Collection $records) => static::changeStatusBulk($records, true))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->recordUrl(fn (RotationPattern $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin patrones de rotación')
            ->emptyStateDescription('Crear un patrón (por ejemplo, 6 días de mañana y 1 franco) para asignarlo a los empleados que rotan.')
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    /**
     * Acción «Duplicar» (fila o página): copia el ciclo bajo otro nombre; no copia las asignaciones.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function duplicateAction(string $class): mixed
    {
        return $class::make('duplicate')
            ->label('Duplicar')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create_rotation_pattern'))
            ->modalHeading('Duplicar patrón')
            ->modalDescription('Se copian el ciclo y la descripción. Los empleados asignados no se copian.')
            ->modalSubmitActionLabel('Duplicar')
            ->fillForm(fn (RotationPattern $record): array => ['name' => "{$record->name} (copia)"])
            ->form(fn (RotationPattern $record): array => [
                TextInput::make('name')
                    ->label('Nombre del nuevo patrón')
                    ->required()
                    ->maxLength(60)
                    ->unique(RotationPattern::class, 'name', modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', $record->company_id))
                    ->validationMessages(['unique' => 'Ya existe un patrón con ese nombre en esta empresa.']),
            ])
            ->action(function (RotationPattern $record, array $data) {
                $copy = $record->duplicate(RotationPattern::capitalizeName($data['name']));

                Notification::make()
                    ->success()
                    ->title('Patrón duplicado')
                    ->body("Se creó \"{$copy->name}\". Revisar el ciclo y asignarlo a los empleados.")
                    ->send();

                return redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Activa o desactiva en bloque, omitiendo los que ya están en ese estado y, al desactivar, los que tienen
     * asignaciones vigentes o futuras.
     *
     * @param  Collection<int, RotationPattern>  $records
     */
    public static function changeStatusBulk(Collection $records, bool $active): void
    {
        $changed = 0;
        $unchanged = 0;
        $blocked = 0;

        foreach ($records as $pattern) {
            if ($pattern->is_active === $active) {
                $unchanged++;

                continue;
            }

            if (! $active && $pattern->deactivationBlockers() !== []) {
                $blocked++;

                continue;
            }

            $pattern->update(['is_active' => $active]);
            $changed++;
        }

        $parts = [];
        if ($blocked > 0) {
            $parts[] = "{$blocked} con asignaciones vigentes o futuras";
        }
        if ($unchanged > 0) {
            $parts[] = "{$unchanged} ya estaban ".($active ? 'activos' : 'inactivos');
        }

        $notification = Notification::make()
            ->title($changed.' '.($changed === 1 ? 'patrón' : 'patrones').' '.($active ? ($changed === 1 ? 'activado' : 'activados') : ($changed === 1 ? 'desactivado' : 'desactivados')));

        $changed > 0 ? $notification->success() : $notification->warning();

        if ($parts !== []) {
            $notification->body('Omitidos: '.implode('; ', $parts).'.');
        }

        $notification->send();
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\EmployeesRelationManager::class,
            RelationManagers\AssignmentsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
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
            'view' => Pages\ViewRotationPattern::route('/{record}'),
            'edit' => Pages\EditRotationPattern::route('/{record}/edit'),
        ];
    }
}
