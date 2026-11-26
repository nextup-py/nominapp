<?php

namespace App\Filament\Resources;

use App\Filament\Actions\ShiftTemplateStatusAction;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\ShiftTemplateResource\Pages;
use App\Models\Company;
use App\Models\Schedule;
use App\Models\ShiftTemplate;
use App\Support\BlockerSummary;
use Carbon\Carbon;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/** Recurso Filament para gestionar los turnos de trabajo reutilizables. */
class ShiftTemplateResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = ShiftTemplate::class;

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationLabel = 'Turnos';

    protected static ?string $modelLabel = 'Turno';

    protected static ?string $pluralModelLabel = 'Turnos';

    protected static ?string $slug = 'turnos';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    /** Hay más de una empresa activa: solo entonces se muestra y se pide la empresa del turno. */
    public static function multipleCompanies(): bool
    {
        return Company::active()->count() > 1;
    }

    /**
     * Detalle de cada turno en la búsqueda global: horario y, si corresponde, que está inactivo.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Horario' => $record->scheduleSummary(),
            'Estado' => $record->is_active ? null : 'Inactivo',
        ]);
    }

    /**
     * Etiqueta de ejemplo con el color elegido, tal como se ve en la grilla del Planificador.
     */
    public static function colorPreview(?string $name, ?string $hex): HtmlString
    {
        $hex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $hex) ? $hex : '#6B7280';
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $text = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.5 ? '#1f2937' : '#ffffff';

        return new HtmlString('<span style="display:inline-block;padding:.25rem .75rem;border-radius:.375rem;font-size:.8rem;font-weight:600;background:'.$hex.';color:'.$text.'">'.e(filled($name) ? $name : 'Turno').'</span>');
    }

    /** Quien puede crear, editar y cambiar el estado de turnos (las acciones personalizadas no consultan la policy). */
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('update_shift_template');
    }

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
                        Placeholder::make('usage_notice')
                            ->label('Turno en uso')
                            ->content(fn (?ShiftTemplate $record): string => 'Está en '.BlockerSummary::format($record?->deletionBlockers() ?? []).'. Los cambios de horario rigen desde ya para esos patrones; las jornadas ya calculadas no cambian hasta recalcularlas.')
                            ->visible(fn (?ShiftTemplate $record): bool => $record !== null && $record->deletionBlockers() !== [])
                            ->columnSpanFull(),

                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(fn (?ShiftTemplate $record) => Company::query()
                                ->where(fn (Builder $query) => $query->where('is_active', true)->when($record?->company_id, fn (Builder $q, $id) => $q->orWhere('id', $id)))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn () => Company::active()->count() === 1 ? Company::active()->value('id') : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->disabled(fn (?ShiftTemplate $record): bool => $record !== null && $record->deletionBlockers() !== [])
                            ->dehydrated()
                            ->helperText(fn (?ShiftTemplate $record): ?string => $record !== null && $record->deletionBlockers() !== [] ? 'No se puede cambiar mientras el turno esté en uso.' : null)
                            ->visible(fn (): bool => static::multipleCompanies() || ! Company::active()->exists()),

                        TextInput::make('name')
                            ->label('Nombre del turno')
                            ->placeholder('Ej: Turno Mañana')
                            ->required()
                            ->maxLength(60)
                            ->live(onBlur: true)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('company_id', $get('company_id') ?: Company::active()->value('id')),
                            )
                            ->validationMessages(['unique' => 'Ya existe un turno con ese nombre en esta empresa. Usar uno distinto para no confundirlos.']),

                        Select::make('shift_type')
                            ->label('Tipo de jornada')
                            ->options(ShiftTemplate::getShiftTypeOptions())
                            ->native(false)
                            ->required(fn (Get $get): bool => ! $get('is_day_off'))
                            ->live()
                            ->placeholder(fn (Get $get): ?string => $get('is_day_off') ? 'No aplica a un franco' : null)
                            ->hint(function (Get $get): ?string {
                                $suggested = $get('is_day_off') ? null : ShiftTemplate::suggestShiftTypeFor($get('start_time'), $get('end_time'));

                                return $suggested && $suggested !== $get('shift_type')
                                    ? 'Según las horas: '.(ShiftTemplate::getShiftTypeLabels()[$suggested] ?? $suggested)
                                    : null;
                            })
                            ->hintColor('info'),

                        ColorPicker::make('color')
                            ->label('Color en el Planificador')
                            ->default('#6B7280')
                            ->required()
                            ->live(),

                        Placeholder::make('color_preview')
                            ->key('color_preview')
                            ->label('Vista previa')
                            ->content(fn (Get $get): HtmlString => static::colorPreview($get('name'), $get('color')))
                            ->hintActions(array_map(
                                fn (string $label, string $hex) => FormAction::make('color_'.Str::slug($label))
                                    ->label($label)
                                    ->icon('heroicon-m-swatch')
                                    ->color('gray')
                                    ->size('xs')
                                    ->action(fn (Set $set) => $set('color', $hex)),
                                array_keys(ShiftTemplate::SUGGESTED_COLORS),
                                array_values(ShiftTemplate::SUGGESTED_COLORS),
                            )),

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
                            ->live()
                            ->required(fn (Get $get) => ! $get('is_day_off'))
                            ->visible(fn (Get $get) => ! $get('is_day_off')),

                        TimePicker::make('end_time')
                            ->label('Hora de salida')
                            ->seconds(false)
                            ->native(false)
                            ->live()
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
                            ->live(onBlur: true)
                            ->visible(fn (Get $get) => ! $get('is_day_off'))
                            ->helperText('Tiempo de descanso descontado del tiempo neto trabajado.'),

                        Placeholder::make('net_summary')
                            ->label('Horas netas')
                            ->content(function (Get $get): string {
                                $start = $get('start_time');
                                $end = $get('end_time');
                                if (! $start || ! $end) {
                                    return 'Completar entrada y salida.';
                                }

                                $startAt = Carbon::parse($start);
                                $endAt = Carbon::parse($end);
                                $crosses = $endAt->lte($startAt);
                                $minutes = (int) $startAt->diffInMinutes($crosses ? $endAt->copy()->addDay() : $endAt) - (int) ($get('break_minutes') ?: 0);

                                return Schedule::formatHours(max($minutes, 0) / 60).($crosses ? ' · Cruza la medianoche' : '');
                            })
                            ->visible(fn (Get $get) => ! $get('is_day_off')),
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
                    ->visible(fn (): bool => static::multipleCompanies()),

                TextColumn::make('shift_type')
                    ->label('Jornada')
                    ->badge()
                    ->placeholder('—')
                    ->getStateUsing(fn (ShiftTemplate $record): ?string => $record->is_day_off ? null : $record->shift_type)
                    ->formatStateUsing(fn ($state) => ShiftTemplate::getShiftTypeLabels()[$state] ?? $state)
                    ->color(fn ($state) => ShiftTemplate::getShiftTypeColors()[$state] ?? 'gray'),

                TextColumn::make('start_time')
                    ->label('Horario')
                    ->getStateUsing(fn (ShiftTemplate $record): string => $record->scheduleSummary())
                    ->description(fn (ShiftTemplate $record): ?string => $record->crossesMidnight() ? 'Cruza la medianoche' : null)
                    ->icon(fn (ShiftTemplate $record): string => $record->is_day_off ? 'heroicon-o-moon' : 'heroicon-o-sun')
                    ->iconColor(fn (ShiftTemplate $record): string => $record->is_day_off ? 'info' : 'success')
                    ->sortable(),

                TextColumn::make('_usage')
                    ->label('Usado en')
                    ->getStateUsing(function (ShiftTemplate $record, $livewire): string {
                        $usage = $livewire->usageMap()[$record->id] ?? ['patterns' => 0, 'overrides' => 0];

                        if ($usage['patterns'] === 0 && $usage['overrides'] === 0) {
                            return 'Sin uso';
                        }

                        return implode(' · ', array_filter([
                            $usage['patterns'] ? $usage['patterns'].($usage['patterns'] === 1 ? ' patrón' : ' patrones') : null,
                            $usage['overrides'] ? $usage['overrides'].($usage['overrides'] === 1 ? ' turno puntual' : ' turnos puntuales') : null,
                        ]));
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Sin uso' ? 'gray' : 'info'),

                TextColumn::make('break_minutes')
                    ->label('Descanso')
                    ->suffix(' min')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
                    ->native(false)
                    ->visible(fn (): bool => static::multipleCompanies()),

                SelectFilter::make('shift_type')
                    ->label('Jornada')
                    ->options(ShiftTemplate::getShiftTypeOptions())
                    ->native(false),

                Filter::make('unused')
                    ->label('Sin uso')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotIn('shift_templates.id', array_keys(ShiftTemplate::usageMap()))),

                TernaryFilter::make('is_day_off')
                    ->label('Tipo')
                    ->trueLabel('Solo francos')
                    ->falseLabel('Solo turnos con horario'),
            ])
            ->actions([
                TableAction::make('view_detail')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (ShiftTemplate $record): string => static::getUrl('view', ['record' => $record])),
                TableActionGroup::make([
                    static::duplicateAction(TableAction::class),
                    static::editAction(TableAction::class),
                    ShiftTemplateStatusAction::make(TableAction::class),
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
                        ->modalHeading('Desactivar turnos')
                        ->modalDescription('Se omiten los que están en patrones de rotación activos o en turnos puntuales de hoy en adelante.')
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
            ->recordUrl(fn (ShiftTemplate $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin turnos')
            ->emptyStateDescription('Crear turnos (Mañana, Tarde, Noche, Franco) para armar patrones de rotación y planificar.')
            ->emptyStateIcon('heroicon-o-table-cells');
    }

    /** Acción de edición de fila o de página con el permiso explícito. */
    public static function editAction(string $class): mixed
    {
        return $class::make('edit_shift')
            ->label('Editar')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->authorize(fn (ShiftTemplate $record): bool => (bool) auth()->user()?->can('update', $record))
            ->url(fn (ShiftTemplate $record): string => static::getUrl('edit', ['record' => $record]));
    }

    /**
     * Acción «Duplicar» (fila o página): copia el turno bajo otro nombre; no copia el uso en patrones ni turnos puntuales.
     *
     * @param  class-string  $class  `Filament\Tables\Actions\Action` o `Filament\Actions\Action`.
     */
    public static function duplicateAction(string $class): mixed
    {
        return $class::make('duplicate')
            ->label('Duplicar')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create_shift_template'))
            ->modalHeading('Duplicar turno')
            ->modalDescription('Se copian el horario, el color y las notas. No se copia el uso en patrones ni en el Planificador.')
            ->modalSubmitActionLabel('Duplicar')
            ->fillForm(fn (ShiftTemplate $record): array => ['name' => "{$record->name} (copia)"])
            ->form(fn (ShiftTemplate $record): array => [
                TextInput::make('name')
                    ->label('Nombre del nuevo turno')
                    ->required()
                    ->maxLength(60)
                    ->unique(ShiftTemplate::class, 'name', modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', $record->company_id))
                    ->validationMessages(['unique' => 'Ya existe un turno con ese nombre en esta empresa.']),
            ])
            ->action(function (ShiftTemplate $record, array $data) {
                $copy = $record->duplicate(ShiftTemplate::capitalizeName($data['name']));

                Notification::make()
                    ->success()
                    ->title('Turno duplicado')
                    ->body("Se creó \"{$copy->name}\". Revisar el horario y el color.")
                    ->send();

                return redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Activa o desactiva en bloque, omitiendo los que ya están en ese estado y, al desactivar, los que están en uso.
     *
     * @param  Collection<int, ShiftTemplate>  $records
     */
    public static function changeStatusBulk(Collection $records, bool $active): void
    {
        $changed = 0;
        $unchanged = 0;
        $blocked = 0;

        foreach ($records as $shift) {
            if ($shift->is_active === $active) {
                $unchanged++;

                continue;
            }

            if (! $active && $shift->deactivationBlockers() !== []) {
                $blocked++;

                continue;
            }

            $shift->update(['is_active' => $active]);
            $changed++;
        }

        $parts = [];
        if ($blocked > 0) {
            $parts[] = "{$blocked} en uso (patrones activos o turnos puntuales)";
        }
        if ($unchanged > 0) {
            $parts[] = "{$unchanged} ya estaban ".($active ? 'activos' : 'inactivos');
        }

        $notification = Notification::make()
            ->title($changed.' '.($changed === 1 ? 'turno' : 'turnos').' '.($active ? ($changed === 1 ? 'activado' : 'activados') : ($changed === 1 ? 'desactivado' : 'desactivados')));

        $changed > 0 ? $notification->success() : $notification->warning();

        if ($parts !== []) {
            $notification->body('Omitidos: '.implode('; ', $parts).'.');
        }

        $notification->send();
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ShiftTemplateResource\RelationManagers\RotationPatternsRelationManager::class,
            ShiftTemplateResource\RelationManagers\OverridesRelationManager::class,
            ShiftTemplateResource\RelationManagers\AuditsRelationManager::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShiftTemplates::route('/'),
            'create' => Pages\CreateShiftTemplate::route('/create'),
            'view' => Pages\ViewShiftTemplate::route('/{record}'),
            'edit' => Pages\EditShiftTemplate::route('/{record}/edit'),
        ];
    }
}
