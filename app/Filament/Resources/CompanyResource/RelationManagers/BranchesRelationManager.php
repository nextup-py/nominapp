<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Exports\BranchesExport;
use App\Models\Branch;
use Cheesegrits\FilamentGoogleMaps\Fields\Map;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Maatwebsite\Excel\Facades\Excel;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    protected static ?string $title = 'Sucursales';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Define el formulario para crear y editar sucursales.
     *
     * @param  Form  $form  The form instance to configure.
     * @return Form The configured form instance.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100)
                    ->unique(
                        table: Branch::class,
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', $this->ownerRecord->id)
                    )
                    ->helperText('Debe ser único en esta empresa.'),

                TextInput::make('phone')
                    ->label('Teléfono')
                    ->tel()
                    ->placeholder('Ej: 0981123456')
                    ->maxLength(10)
                    ->regex('/^0\d{8,9}$/')
                    ->validationMessages([
                        'regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).',
                    ])
                    ->helperText('Número de teléfono móvil o fijo.'),

                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->placeholder('Ej: contacto@empresa.com')
                    ->email()
                    ->maxLength(100)
                    ->unique(Branch::class, 'email', ignoreRecord: true)
                    ->helperText('Debe ser único entre las sucursales.'),

                Grid::make(2)
                    ->schema([
                        TextInput::make('address')
                            ->label('Dirección')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Ej: Av. España 1234 c/ Brasil')
                            ->helperText('Incluye calle, número y referencias si es necesario.'),

                        TextInput::make('city')
                            ->label('Ciudad')
                            ->readOnly()
                            ->maxLength(100)
                            ->helperText('Se completa automáticamente al ubicar el pin en el mapa.'),
                    ]),

                Map::make('coordinates')
                    ->label('Ubicación en el mapa')
                    ->columnSpanFull()
                    ->defaultLocation([-25.2867, -57.6478]) // Asunción, Paraguay
                    ->draggable()
                    ->clickable()
                    ->autocomplete('address')
                    ->autocompleteReverse(true)
                    ->reverseGeocode([
                        'address' => '%n %S',
                        'city' => '%L',
                    ])
                    ->geolocate()
                    ->height('300px'),
            ])
            ->columns(3);
    }

    /**
     * Define la tabla para listar las sucursales.
     *
     * @param Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->description(fn ($record) => $record->address)
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('city')
                    ->label('Ciudad')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('contact_info')
                    ->label('Contacto')
                    ->getStateUsing(fn ($record) => $record->phone ?: 'Sin teléfono')
                    ->description(fn ($record) => $record->email ?: null)
                    ->icon('heroicon-o-phone')
                    ->placeholder('Sin contacto'),

                TextColumn::make('active_employees_count')
                    ->label('Empleados Activos')
                    ->counts('activeEmployees')
                    ->badge()
                    ->color('success')
                    ->alignCenter(),
            ])
            ->headerActions([
                Action::make('export_excel')
                    ->label('Exportar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Exportar Sucursales a Excel?')
                    ->modalDescription('Se incluirán todas las sucursales de esta empresa en un archivo Excel descargable.')
                    ->modalSubmitActionLabel('Sí, exportar')
                    ->action(function () {
                        Notification::make()
                            ->success()
                            ->title('Exportación lista')
                            ->body('El listado de sucursales se está descargando.')
                            ->send();

                        return Excel::download(
                            new BranchesExport($this->ownerRecord->id),
                            'sucursales_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                        );
                    }),

                CreateAction::make()
                    ->label('Nueva Sucursal')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Crear nueva sucursal'),
            ])
            ->actions([
                Action::make('view_map')
                    ->label('Ver en mapa')
                    ->icon('heroicon-o-map')
                    ->color('info')
                    ->url(
                        fn ($record) => isset($record->coordinates['lat'], $record->coordinates['lng'])
                            ? sprintf('https://www.google.com/maps?q=%s,%s', $record->coordinates['lat'], $record->coordinates['lng'])
                            : null
                    )
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => isset($record->coordinates['lat'], $record->coordinates['lng'])),

                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->color('primary'),

                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->modalHeading('¿Eliminar sucursal?')
                        ->modalDescription(fn ($record) => $record->deletionBlockers() !== []
                            ? "La sucursal \"{$record->name}\" tiene {$record->deletionBlockersSummary()} y no se puede eliminar."
                            : "Se eliminará permanentemente la sucursal \"{$record->name}\".")
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->before(function ($record, $action) {
                            if ($record->deletionBlockers() !== []) {
                                Notification::make()
                                    ->danger()
                                    ->title('No se puede eliminar')
                                    ->body("La sucursal \"{$record->name}\" tiene {$record->deletionBlockersSummary()}. Reasígnelos antes de eliminarla.")
                                    ->send();
                                $action->halt();
                            }
                        }),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->color('gray')
                    ->tooltip('Más acciones'),
            ])
            ->bulkActions([])
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('name')
            ->emptyStateHeading('No hay sucursales registradas')
            ->emptyStateDescription('Agrega la primera sucursal de esta empresa.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
