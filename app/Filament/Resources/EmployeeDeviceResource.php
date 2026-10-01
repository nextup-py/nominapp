<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeDeviceResource\Pages;
use App\Filament\Traits\HasModuleAccess;
use App\Models\EmployeeDevice;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial de dispositivos personales vinculados por empleados para
 * marcación offline vía PWA — contraparte de `TerminalResource` para
 * dispositivos propios (no terminales compartidos). Registros creados
 * automáticamente por `Employee::claimMobileToken()`/`revokeMobileToken()`;
 * este recurso solo permite anotar marca/modelo/serie/MAC/notas a mano,
 * igual que ya se hace con los terminales.
 */
class EmployeeDeviceResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'biometric_attendance_enabled';

    protected static ?string $model = EmployeeDevice::class;

    protected static ?string $navigationLabel = 'Dispositivos de Empleados';

    protected static ?string $label = 'dispositivo';

    protected static ?string $pluralLabel = 'dispositivos de empleados';

    protected static ?string $slug = 'dispositivos-empleados';

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 7;

    /**
     * Formulario de edición — solo los campos anotables a mano. Vinculación,
     * revocación, empleado y user agent son de solo lectura (gestionados por
     * el ciclo de vida real de la vinculación, no editables desde acá).
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Dispositivo')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->compact()
                    ->schema([
                        TextInput::make('device_brand')
                            ->label('Marca')
                            ->placeholder('Ej: Samsung, Apple, Motorola')
                            ->helperText('Se sugiere automáticamente al vincular el dispositivo, cuando el navegador lo permite. Editable.')
                            ->maxLength(60),

                        TextInput::make('device_model')
                            ->label('Modelo')
                            ->placeholder('Ej: Galaxy A54')
                            ->helperText('Igual que la marca: sugerido automáticamente, no siempre disponible (ej. iPhone/iPad nunca lo reportan).')
                            ->maxLength(100),

                        TextInput::make('device_serial')
                            ->label('Número de Serie')
                            ->maxLength(100),

                        TextInput::make('device_mac')
                            ->label('Dirección MAC')
                            ->placeholder('AA:BB:CC:DD:EE:FF')
                            ->maxLength(17)
                            ->regex('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/')
                            ->validationMessages(['regex' => 'Ingrese una dirección MAC válida. Ej: AA:BB:CC:DD:EE:FF']),

                        Textarea::make('device_notes')
                            ->label('Notas del dispositivo')
                            ->placeholder('Ej: Celular personal, pantalla de 6.5"')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Vinculación')
                    ->icon('heroicon-o-link')
                    ->compact()
                    ->schema([
                        Placeholder::make('employee_name')
                            ->label('Empleado')
                            ->content(fn (EmployeeDevice $record) => $record->employee->full_name),

                        Placeholder::make('linked_at_display')
                            ->label('Vinculado el')
                            ->content(fn (EmployeeDevice $record) => $record->linked_at->format('d/m/Y H:i')),

                        Placeholder::make('unlinked_at_display')
                            ->label('Desvinculado el')
                            ->content(fn (EmployeeDevice $record) => $record->unlinked_at?->format('d/m/Y H:i') ?? 'Sigue vinculado'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Infolist de visualización del dispositivo.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Vinculación')
                    ->schema([
                        InfoGrid::make(3)->schema([
                            TextEntry::make('employee.full_name')
                                ->label('Empleado')
                                ->icon('heroicon-o-user')
                                ->url(fn (EmployeeDevice $record) => EmployeeResource::getUrl('view', ['record' => $record->employee_id])),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => EmployeeDevice::getStatusLabels()[$state] ?? $state)
                                ->color(fn (string $state) => EmployeeDevice::getStatusColors()[$state] ?? 'gray'),

                            TextEntry::make('user_agent')
                                ->label('Navegador')
                                ->placeholder('Sin datos')
                                ->limit(40),
                        ]),

                        InfoGrid::make(2)->schema([
                            TextEntry::make('linked_at')
                                ->label('Vinculado el')
                                ->dateTime('d/m/Y H:i'),

                            TextEntry::make('unlinked_at')
                                ->label('Desvinculado el')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Sigue vinculado'),
                        ]),
                    ]),

                InfoSection::make('Dispositivo')
                    ->schema([
                        InfoGrid::make(3)->schema([
                            TextEntry::make('device_brand')
                                ->label('Marca')
                                ->placeholder('Sin datos'),

                            TextEntry::make('device_model')
                                ->label('Modelo')
                                ->placeholder('Sin datos'),

                            TextEntry::make('device_serial')
                                ->label('Número de Serie')
                                ->copyable()
                                ->placeholder('Sin datos'),
                        ]),

                        TextEntry::make('device_mac')
                            ->label('Dirección MAC')
                            ->copyable()
                            ->placeholder('Sin datos'),

                        TextEntry::make('device_notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Tabla con el historial de dispositivos de todos los empleados.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn (Builder $q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => EmployeeDevice::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => EmployeeDevice::getStatusColors()[$state] ?? 'gray'),

                TextColumn::make('device_description')
                    ->label('Dispositivo')
                    ->placeholder('Sin datos')
                    ->toggleable(),

                TextColumn::make('linked_at')
                    ->label('Vinculado')
                    ->dateTime('d/m/Y H:i')
                    ->since()
                    ->sortable(),

                TextColumn::make('unlinked_at')
                    ->label('Desvinculado')
                    ->since()
                    ->placeholder('Sigue vinculado')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('device_mac')
                    ->label('MAC')
                    ->placeholder('Sin datos')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => $record->first_name.' '.$record->last_name.' (CI: '.$record->ci.')'
                    )
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EmployeeDevice::getStatusLabels())
                    ->native(false)
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'active' => $query->whereNull('unlinked_at'),
                            'unlinked' => $query->whereNotNull('unlinked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Action::make('revoke')
                    ->label('Revocar')
                    ->tooltip('Invalida el acceso de este dispositivo a la marcación offline — el empleado deberá vincularse de nuevo con CI + fecha de nacimiento')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (EmployeeDevice $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Revocar dispositivo')
                    ->modalDescription(fn (EmployeeDevice $record) => "El dispositivo vinculado de {$record->employee->full_name} perderá acceso a la marcación offline de inmediato. Deberá vincularse de nuevo con CI + fecha de nacimiento.")
                    ->modalSubmitActionLabel('Sí, revocar')
                    ->action(function (EmployeeDevice $record) {
                        $record->employee->revokeMobileToken();

                        Notification::make()
                            ->success()
                            ->title('Dispositivo revocado')
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('linked_at', 'desc')
            ->emptyStateHeading('Sin dispositivos vinculados')
            ->emptyStateDescription('Los dispositivos aparecen acá automáticamente cuando un empleado se vincula desde /vincular-dispositivo.')
            ->emptyStateIcon('heroicon-o-device-phone-mobile');
    }

    /**
     * Relaciones del recurso.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * Páginas del recurso — sin 'create': los registros se generan
     * automáticamente al vincular un dispositivo, nunca a mano.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeDevices::route('/'),
            'view' => Pages\ViewEmployeeDevice::route('/{record}'),
            'edit' => Pages\EditEmployeeDevice::route('/{record}/edit'),
        ];
    }

    /**
     * Badge de navegación: cantidad de dispositivos actualmente vinculados.
     */
    public static function getNavigationBadge(): ?string
    {
        $active = EmployeeDevice::whereNull('unlinked_at')->count();

        return $active > 0 ? (string) $active : null;
    }
}
