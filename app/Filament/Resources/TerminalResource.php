<?php

namespace App\Filament\Resources;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\TerminalCommandActions;
use App\Filament\Actions\TerminalLinkActions;
use App\Filament\Actions\TerminalMonitoringActions;
use App\Filament\Actions\TerminalPairingActions;
use App\Filament\Resources\TerminalResource\Pages;
use App\Filament\Resources\TerminalResource\RelationManagers\AttendanceEventsRelationManager;
use App\Filament\Resources\TerminalResource\RelationManagers\CommandsRelationManager;
use App\Filament\Resources\TerminalResource\RelationManagers\DevicesRelationManager;
use App\Filament\Resources\TerminalResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\TerminalResource\RelationManagers\PairingRequestsRelationManager;
use App\Filament\Resources\TerminalResource\RelationManagers\ReliabilityRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Company;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use App\Support\PhoneNormalizer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/** Gestión de terminales físicas de marcación de asistencia. */
class TerminalResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'biometric_attendance_enabled';

    protected static ?string $model = Terminal::class;

    protected static ?string $navigationLabel = 'Terminales';

    protected static ?string $label = 'terminal';

    protected static ?string $pluralLabel = 'terminales';

    protected static ?string $slug = 'terminales';

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Terminales y dispositivos';

    protected static ?int $navigationSort = 1;

    /**
     * Formulario de creación y edición de terminales.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identificación')
                    ->icon('heroicon-o-computer-desktop')
                    ->compact()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ej: Terminal Entrada Principal')
                            ->required()
                            ->maxLength(100),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            // Excluye sucursales inactivas y de empresas inactivas de las opciones — pero
                            // nunca de la sucursal YA asignada al editar: modifyQueryUsing()
                            // también filtra la query que resuelve la etiqueta del valor
                            // actual (Select::getSelectedRecordUsing()), así que sin el OR con
                            // $record?->branch_id, editar un terminal cuya empresa se
                            // desactivó después dejaría el campo en blanco.
                            ->relationship('branch', 'name', modifyQueryUsing: fn (Builder $query, ?Terminal $record) => $query
                                ->where(function (Builder $query) use ($record) {
                                    $query->where('is_active', true)
                                        ->whereHas('company', fn (Builder $query) => $query->active());

                                    if ($record?->branch_id) {
                                        $query->orWhere('id', $record->branch_id);
                                    }
                                }))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->hint(fn (?Terminal $record, Get $get): ?string => $record?->hasActiveSyncToken()
                                && filled($get('branch_id'))
                                && (int) $get('branch_id') !== $record->branch_id
                                    ? 'Al cambiar de sucursal, el terminal reconocerá a los empleados de la nueva.'
                                    : null)
                            ->hintColor('warning')
                            ->hintIcon(fn (?Terminal $record, Get $get): ?string => $record?->hasActiveSyncToken()
                                && filled($get('branch_id'))
                                && (int) $get('branch_id') !== $record->branch_id
                                    ? 'heroicon-o-exclamation-triangle'
                                    : null),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Terminal::getStatusOptions())
                            ->helperText('Al desactivarlo se desvincula el dispositivo y deja de marcar.')
                            ->native(false)
                            ->default('active')
                            ->required()
                            ->hiddenOn('create'),

                        Toggle::make('is_mobile')
                            ->label('Equipo móvil')
                            ->helperText('Celular o tablet que puede salir de la sucursal: se avisa si se aleja. Pide permiso de ubicación; las PC fijas no lo necesitan.')
                            ->default(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Marcación y monitoreo')
                    ->description('Sin definir, usa Configuración General (y, para el horario, el de la sucursal).')
                    ->icon('heroicon-o-wifi')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (?Terminal $record): bool => $record === null || ($record->confirm_exit === null
                        && blank($record->stale_after_minutes)
                        && blank($record->watch_days)
                        && blank($record->watch_from)
                        && blank($record->watch_to)))
                    ->schema([
                        static::confirmExitField()->columnSpanFull(),

                        ...static::monitoringFields(),
                    ])
                    ->columns(2),

                Section::make('Dispositivo')
                    ->description('Para el inventario. Todo es opcional.')
                    ->icon('heroicon-o-device-tablet')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (?Terminal $record): bool => $record === null || (blank($record->device_brand)
                        && blank($record->device_model)
                        && blank($record->device_serial)
                        && blank($record->device_mac)
                        && blank($record->device_notes)))
                    ->schema([
                        TextInput::make('device_brand')
                            ->label('Marca')
                            ->placeholder('Ej: Samsung, Apple, Lenovo')
                            ->helperText('Se completa al vincular si el navegador lo informa.')
                            ->maxLength(60),

                        TextInput::make('device_model')
                            ->label('Modelo')
                            ->placeholder('Ej: Galaxy Tab A8')
                            ->maxLength(100),

                        TextInput::make('device_serial')
                            ->label('Número de Serie')
                            ->maxLength(100),

                        TextInput::make('device_mac')
                            ->label('Dirección MAC')
                            ->placeholder('AA:BB:CC:DD:EE:FF')
                            ->maxLength(17)
                            ->regex('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/')
                            ->validationMessages(['regex' => 'Ingresar una dirección MAC válida. Ej: AA:BB:CC:DD:EE:FF.']),

                        Textarea::make('device_notes')
                            ->label('Notas del dispositivo')
                            ->placeholder('Ej: Pantalla con rayón en esquina superior derecha')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Responsable y garantía')
                    ->description('A quién llamar si el equipo falla y hasta cuándo tiene garantía.')
                    ->icon('heroicon-o-user-circle')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (?Terminal $record): bool => $record === null || (blank($record->responsible_name)
                        && blank($record->responsible_phone)
                        && blank($record->purchased_at)
                        && blank($record->warranty_until)))
                    ->schema([
                        TextInput::make('responsible_name')
                            ->label('Responsable del equipo')
                            ->placeholder('Ej: Richard Sánchez')
                            ->helperText('Aparece en el aviso de desconexión.')
                            ->maxLength(100),

                        TextInput::make('responsible_phone')
                            ->label('Teléfono del responsable')
                            ->placeholder('Ej: 0981123456')
                            ->maxLength(10)
                            ->regex(PhoneNormalizer::PATTERN)
                            ->live(onBlur: true)
                            ->afterStateUpdated(PhoneNormalizer::onBlur())
                            ->validationMessages(['regex' => 'Ingresar un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).'])
                            ->helperText('Sin espacios ni guiones.'),

                        DatePicker::make('purchased_at')
                            ->label('Fecha de compra')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->maxDate(now()),

                        DatePicker::make('warranty_until')
                            ->label('Garantía hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->afterOrEqual('purchased_at')
                            ->validationMessages(['after_or_equal' => 'La garantía no puede vencer antes de la fecha de compra.']),
                    ])
                    ->columns(2),

                Section::make('Instalación')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (?Terminal $record): bool => $record !== null
                        && blank($record->installed_at)
                        && blank($record->installed_by_id))
                    ->schema([
                        DatePicker::make('installed_at')
                            ->label('Fecha de instalación')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->default(fn () => now()->toDateString()),

                        Select::make('installed_by_id')
                            ->label('Instalado por')
                            ->relationship('installedBy', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->default(fn () => Auth::id()),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Infolist de visualización de la terminal con QR de acceso.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Identificación')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('name')
                                ->label('Nombre')
                                ->icon('heroicon-o-computer-desktop'),

                            TextEntry::make('branch.company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office-2')
                                ->badge()
                                ->color('primary')
                                ->visible(fn () => Company::active()->count() > 1),

                            TextEntry::make('branch.name')
                                ->label('Sucursal')
                                ->icon('heroicon-o-building-storefront')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => Terminal::getStatusLabels()[$state] ?? $state)
                                ->color(fn (string $state) => Terminal::getStatusColors()[$state] ?? 'gray')
                                ->badge(),
                        ]),

                        InfoGrid::make(2)->schema([
                            TextEntry::make('code')
                                ->label('Código de terminal')
                                ->icon('heroicon-o-key')
                                ->badge()
                                ->color('gray')
                                ->copyable()
                                ->copyMessage('Código copiado'),

                            TextEntry::make('url')
                                ->label('URL de acceso')
                                ->icon('heroicon-o-link')
                                ->copyable()
                                ->copyMessage('URL copiada')
                                ->state(fn (Terminal $record) => $record->url),
                        ]),

                        ImageEntry::make('qr_code')
                            ->label('QR de acceso')
                            // No usar TextEntry->html() acá: el sanitizador HTML de Filament
                            // (Symfony HtmlSanitizer, vía Str::sanitizeHtml()) elimina el <svg>
                            // completo porque SVG no está en su lista de elementos "seguros" —
                            // el QR quedaba invisible (solo el <div> contenedor vacío). Un data
                            // URI en ImageEntry evita el sanitizador por completo: Filament lo
                            // detecta (str($state)->startsWith('data:')) y lo usa tal cual como
                            // src de <img>, sin pasar por el pipeline de HTML.
                            ->state(fn (Terminal $record) => 'data:image/svg+xml;base64,'
                                .base64_encode((string) QrCode::size(180)->generate($record->url))
                            )
                            ->height(180)
                            ->extraImgAttributes([
                                'style' => 'background:#fff;padding:12px;border-radius:8px;border:1px solid #e5e7eb',
                                'alt' => 'Código QR de acceso al terminal',
                            ]),
                    ]),

                InfoSection::make('Dispositivo')
                    ->collapsible()
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

                        TextEntry::make('user_agent')
                            ->label('Navegador (detectado al provisionar)')
                            ->placeholder('Sin datos')
                            ->limit(60)
                            ->tooltip(fn (Terminal $record) => $record->user_agent),

                        TextEntry::make('device_notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Terminal $record) => $record->device_brand || $record->device_model || $record->device_serial || $record->device_mac || $record->device_notes || $record->user_agent),

                InfoSection::make('Responsable y garantía')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(2)->schema([
                            TextEntry::make('responsible_name')
                                ->label('Responsable del equipo')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('Sin responsable'),

                            TextEntry::make('responsible_phone')
                                ->label('Teléfono del responsable')
                                ->icon('heroicon-o-phone')
                                ->copyable()
                                ->placeholder('Sin teléfono'),

                            TextEntry::make('purchased_at')
                                ->label('Fecha de compra')
                                ->date('d/m/Y')
                                ->placeholder('Sin fecha de compra'),

                            TextEntry::make('warranty_until')
                                ->label('Garantía hasta')
                                ->date('d/m/Y')
                                ->placeholder('Sin garantía cargada')
                                ->badge()
                                ->color(fn (Terminal $record) => Terminal::getWarrantyStatusColors()[$record->warrantyStatus()] ?? 'gray')
                                ->helperText(fn (Terminal $record) => $record->warranty_until ? Terminal::getWarrantyStatusOptions()[$record->warrantyStatus()] : null),
                        ]),
                    ])
                    ->visible(fn (Terminal $record) => $record->responsible_name || $record->responsible_phone || $record->purchased_at || $record->warranty_until),

                InfoSection::make('Conectividad')
                    ->description('Marcación offline vía PWA — heartbeat y sincronización con la API de terminales')
                    ->icon('heroicon-o-wifi')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('connectivity_status')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => Terminal::getConnectivityStatusLabels()[$state] ?? $state)
                                ->color(fn (string $state) => Terminal::getConnectivityStatusColors()[$state] ?? 'gray'),

                            TextEntry::make('last_heartbeat_at')
                                ->label('Último heartbeat')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Nunca')
                                ->since(),

                            TextEntry::make('last_employee_sync_at')
                                ->label('Último sync de empleados')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Nunca')
                                ->since(),

                            TextEntry::make('last_event_sync_at')
                                ->label('Último sync de marcaciones')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Nunca')
                                ->since(),
                        ]),

                        InfoGrid::make(3)->schema([
                            TextEntry::make('sync_queue_status')
                                ->label('Cola de sincronización')
                                ->badge()
                                ->tooltip('Reportado por el terminal en cada heartbeat')
                                ->formatStateUsing(fn (string $state) => Terminal::getSyncQueueStatusLabels()[$state] ?? $state)
                                ->color(fn (string $state) => Terminal::getSyncQueueStatusColors()[$state] ?? 'gray'),

                            TextEntry::make('last_pending_events_count')
                                ->label('Marcaciones pendientes')
                                ->placeholder('Sin datos'),

                            TextEntry::make('last_conflict_events_count')
                                ->label('Marcaciones en conflicto')
                                ->placeholder('Sin datos'),
                        ]),

                        InfoGrid::make(3)->schema([
                            TextEntry::make('last_seen_at')
                                ->label('Última carga de página')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Sin actividad registrada')
                                ->since(),

                            TextEntry::make('_stale_threshold')
                                ->label('Umbral de desconexión')
                                ->getStateUsing(fn (Terminal $record) => $record->effectiveStaleMinutes().' minutos'.($record->stale_after_minutes ? '' : ' (general)')),

                            TextEntry::make('_confirm_exit')
                                ->label('Confirmar salida y descansos')
                                ->getStateUsing(fn (Terminal $record) => static::describeConfirmExit($record)),

                            TextEntry::make('_watch_schedule')
                                ->label('Horario de vigilancia')
                                ->getStateUsing(fn (Terminal $record) => $record->watchScheduleLabel()),
                        ]),
                    ]),

                InfoSection::make('Recomendaciones')
                    ->description('Qué conviene corregir en el equipo según su último reporte. Un equipo que también se usa para otra cosa (por ejemplo, un celular de ventas) puede ser terminal si se mantiene enchufado, con la pantalla encendida y con esta app visible.')
                    ->icon('heroicon-o-light-bulb')
                    ->collapsible()
                    ->schema([
                        TextEntry::make('_recommendations')
                            ->label('')
                            ->getStateUsing(fn (Terminal $record): array => collect($record->recommendations())->map(fn (array $item): string => "{$item['title']}: {$item['text']}")->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Terminal $record): bool => $record->recommendations() !== []),

                InfoSection::make('Estado del dispositivo')
                    ->description('Lo que el terminal informó en su último heartbeat. Cada dato depende de lo que el navegador permita leer.')
                    ->icon('heroicon-o-cpu-chip')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(4)->schema([
                            TextEntry::make('_app_version')
                                ->label('Versión de la app')
                                ->getStateUsing(fn (Terminal $record) => static::describeAppVersion($record))
                                ->placeholder('Sin dato'),

                            TextEntry::make('_install_mode')
                                ->label('Modo')
                                ->getStateUsing(fn (Terminal $record) => static::describeInstallMode($record))
                                ->placeholder('Sin dato'),

                            TextEntry::make('_location')
                                ->label('Ubicación')
                                ->getStateUsing(fn (Terminal $record) => static::describeLocation($record))
                                ->visible(fn (Terminal $record): bool => (bool) $record->is_mobile)
                                ->placeholder('Sin lectura'),

                            TextEntry::make('_battery')
                                ->label('Batería')
                                ->getStateUsing(fn (Terminal $record) => static::describeBattery($record))
                                ->placeholder('El navegador no la informa'),

                            TextEntry::make('_camera')
                                ->label('Cámara')
                                ->getStateUsing(fn (Terminal $record) => static::describeCamera($record))
                                ->placeholder('Sin dato'),
                        ]),

                        InfoGrid::make(4)->schema([
                            TextEntry::make('_cached_employees')
                                ->label('Empleados en caché')
                                ->getStateUsing(fn (Terminal $record) => $record->device_report['cached_employees'] ?? null)
                                ->placeholder('Sin dato'),

                            TextEntry::make('_clock_skew')
                                ->label('Desfase de reloj')
                                ->getStateUsing(fn (Terminal $record) => static::describeClockSkew($record))
                                ->placeholder('Sin dato'),

                            TextEntry::make('_wake_lock')
                                ->label('Pantalla encendida')
                                ->getStateUsing(fn (Terminal $record) => static::describeWakeLock($record))
                                ->placeholder('Sin dato'),

                            TextEntry::make('_storage')
                                ->label('Almacenamiento')
                                ->getStateUsing(fn (Terminal $record) => static::describeStorage($record))
                                ->placeholder('Sin dato'),

                            TextEntry::make('_identification')
                                ->label('Tiempo de identificación')
                                ->getStateUsing(fn (Terminal $record) => static::describeIdentification($record))
                                ->color(fn (Terminal $record) => $record->isIdentificationSlow() ? 'warning' : null)
                                ->helperText('Cuánto tarda este equipo en reconocer a una persona. Pasa por completo en el navegador y no se ve de otro modo.')
                                ->placeholder('Todavía no hubo marcaciones desde que se abrió'),

                            TextEntry::make('device_report_at')
                                ->label('Último reporte')
                                ->dateTime('d/m/Y H:i')
                                ->since()
                                ->placeholder('Sin reporte'),
                        ]),

                        InfoGrid::make(4)->schema([
                            TextEntry::make('_device_id')
                                ->label('ID del dispositivo')
                                ->getStateUsing(fn (Terminal $record) => $record->activeDevice?->shortDeviceId())
                                ->helperText('Identificador aleatorio que genera el propio equipo; sirve para distinguirlo de otro con la misma IP.')
                                ->fontFamily('mono')
                                ->placeholder('Aún no informado'),

                            TextEntry::make('_system')
                                ->label('Sistema y navegador')
                                ->getStateUsing(fn (Terminal $record) => static::describeSystem($record))
                                ->placeholder('No detectado'),

                            TextEntry::make('_hardware')
                                ->label('Equipo')
                                ->getStateUsing(fn (Terminal $record) => static::describeHardware($record))
                                ->placeholder('El navegador no lo informa'),

                            TextEntry::make('_connection_screen')
                                ->label('Conexión y pantalla')
                                ->getStateUsing(fn (Terminal $record) => static::describeConnection($record))
                                ->placeholder('El navegador no lo informa'),
                        ]),
                    ])
                    ->visible(fn (Terminal $record) => $record->device_report !== null),

                InfoSection::make('Dispositivo vinculado')
                    ->description('Dispositivo que hoy tiene acceso a la sincronización offline de este terminal')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(3)->schema([
                            TextEntry::make('linked_at')
                                ->label('Vinculado el')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Sin registro'),

                            TextEntry::make('_linked_via')
                                ->label('Vinculado mediante')
                                ->getStateUsing(fn (Terminal $record) => static::describeLinkOrigin($record)),

                            TextEntry::make('linked_ip')
                                ->label('IP al vincular')
                                ->placeholder('Sin registro'),
                        ]),

                        InfoGrid::make(3)->schema([
                            TextEntry::make('device_label')
                                ->label('Dispositivo')
                                ->getStateUsing(fn (Terminal $record) => trim("{$record->device_brand} {$record->device_model}") ?: null)
                                ->placeholder('Sin datos'),

                            TextEntry::make('last_heartbeat_at')
                                ->label('Último contacto')
                                ->since()
                                ->placeholder('Sin contacto todavía'),
                        ]),
                    ])
                    ->visible(fn (Terminal $record) => $record->hasActiveSyncToken()),

                InfoSection::make('Ventana de vinculación')
                    ->description('Mientras esté abierta, el primer dispositivo que pida vincularse queda vinculado sin aprobación')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        InfoGrid::make(2)->schema([
                            TextEntry::make('link_window_until')
                                ->label('Se cierra')
                                ->dateTime('d/m/Y H:i')
                                ->since(),

                            TextEntry::make('linkWindowOpenedBy.name')
                                ->label('Abierta por')
                                ->placeholder('Sin registro'),
                        ]),
                    ])
                    ->visible(fn (Terminal $record) => $record->hasOpenLinkWindow()),

                InfoSection::make('Instalación')
                    ->collapsible()
                    ->schema([
                        InfoGrid::make(2)->schema([
                            TextEntry::make('installed_at')
                                ->label('Fecha de instalación')
                                ->date('d/m/Y')
                                ->placeholder('Sin fecha de instalación'),

                            TextEntry::make('installedBy.name')
                                ->label('Instalado por')
                                ->placeholder('Sin registrar'),
                        ]),
                    ])
                    ->visible(fn (Terminal $record) => $record->installed_at || $record->installed_by_id),
            ]);
    }

    /**
     * Tabla de terminales con columnas, filtros y acciones de ciclo de vida.
     */
    /**
     * Precarga el flag de token vigente para que `connectivity_status` (tabla y
     * vista) no dispare una consulta de tokens por terminal.
     *
     * @return Builder<Terminal>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withSyncTokenFlag()->with('branch');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('branch.company.name')
                    ->label('Empresa')
                    ->icon('heroicon-o-building-office-2')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-o-building-storefront')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Código')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('device_description')
                    ->label('Dispositivo')
                    ->placeholder('Sin datos')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Terminal::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getStatusColors()[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('connectivity_status')
                    ->label('Conectividad')
                    ->badge()
                    ->tooltip('Sin vincular: sin token de sincronización vigente. Desconectado: sin heartbeat dentro del umbral (propio o general). Fuera de horario: desconectado pero fuera de su horario de vigilancia')
                    ->formatStateUsing(fn (string $state) => Terminal::getConnectivityStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getConnectivityStatusColors()[$state] ?? 'gray'),

                TextColumn::make('display_kind')
                    ->label('Modo')
                    ->badge()
                    ->getStateUsing(fn (Terminal $record) => $record->displayKind())
                    ->formatStateUsing(fn (?string $state) => Terminal::getDisplayKindLabels()[$state] ?? $state)
                    ->color(fn (?string $state) => Terminal::getDisplayKindColors()[$state] ?? 'gray')
                    ->placeholder('Sin dato')
                    ->tooltip('App o kiosko: ventana propia (app instalada o pantalla completa). Pestaña: abierto en una pestaña del navegador, que puede quedar oculta y dejar que el equipo se suspenda. Según el último reporte del terminal'),

                TextColumn::make('link_window')
                    ->label('Ventana de vinculación')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-clock')
                    ->getStateUsing(fn (Terminal $record) => $record->hasOpenLinkWindow() ? 'Abierta hasta '.$record->link_window_until->format('H:i') : null)
                    ->tooltip('Mientras esté abierta, el primer dispositivo que pida vincularse queda vinculado sin aprobación'),

                TextColumn::make('sync_queue_status')
                    ->label('Cola de sync')
                    ->badge()
                    ->tooltip('Marcaciones pendientes o en conflicto reportadas por el terminal en su último heartbeat')
                    ->formatStateUsing(fn (string $state) => Terminal::getSyncQueueStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getSyncQueueStatusColors()[$state] ?? 'gray'),

                TextColumn::make('last_heartbeat_at')
                    ->label('Último heartbeat')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('last_seen_at')
                    ->label('Última actividad')
                    ->since()
                    ->placeholder('Sin actividad')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('responsible_name')
                    ->label('Responsable')
                    ->description(fn (Terminal $record) => $record->responsible_phone)
                    ->placeholder('Sin responsable')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('warranty_until')
                    ->label('Garantía')
                    ->date('d/m/Y')
                    ->placeholder('Sin datos')
                    ->badge()
                    ->color(fn (Terminal $record) => Terminal::getWarrantyStatusColors()[$record->warrantyStatus()] ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('installed_at')
                    ->label('Instalada')
                    ->date('d/m/Y')
                    ->placeholder('Sin instalar')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->visible(fn () => Company::active()->count() > 1)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('branch', fn ($q) => $q->where('company_id', $data['value']))
                        : $query
                    ),

                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Terminal::getStatusOptions())
                    ->native(false),

                SelectFilter::make('connectivity_status')
                    ->label('Conectividad')
                    ->options(Terminal::getConnectivityStatusOptions())
                    ->native(false)
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $outsideWindow = Terminal::idsOutsideWatchWindow();

                        // Misma prioridad que Terminal::connectivity_status: la vinculación manda
                        // sobre el heartbeat, así que los demás estados exigen token vigente; y un
                        // heartbeat vencido es 'stale' o 'off_hours' según el horario de vigilancia.
                        return match ($data['value']) {
                            'unlinked' => $query->syncUnlinked(),
                            'never_connected' => $query->syncLinked()->whereNull('last_heartbeat_at'),
                            'online' => $query->syncLinked()->heartbeatFresh(),
                            'stale' => $query->syncLinked()->heartbeatStale()->whereNotIn('terminals.id', $outsideWindow),
                            'off_hours' => $query->syncLinked()->heartbeatStale()->whereIn('terminals.id', $outsideWindow),
                            default => $query,
                        };
                    }),

                SelectFilter::make('display_kind')
                    ->label('Modo de ejecución')
                    ->options(Terminal::getDisplayKindLabels())
                    ->native(false)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('terminals.id', Terminal::idsByDisplayKind($data['value']))
                        : $query),

                SelectFilter::make('warranty_status')
                    ->label('Garantía')
                    ->options(Terminal::getWarrantyStatusOptions())
                    ->native(false)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->withWarrantyStatus($data['value'])
                        : $query),

                Filter::make('link_window_open')
                    ->label('Ventana de vinculación abierta')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->withOpenLinkWindow()),

                SelectFilter::make('sync_queue_status')
                    ->label('Cola de sync')
                    ->options(Terminal::getSyncQueueStatusOptions())
                    ->native(false)
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'conflict' => $query->where('last_conflict_events_count', '>', 0),
                            'pending' => $query->where('last_pending_events_count', '>', 0)
                                ->where(fn ($q) => $q->whereNull('last_conflict_events_count')->orWhere('last_conflict_events_count', 0)),
                            'ok' => $query->where(fn ($q) => $q->whereNull('last_pending_events_count')->orWhere('last_pending_events_count', 0))
                                ->where(fn ($q) => $q->whereNull('last_conflict_events_count')->orWhere('last_conflict_events_count', 0)),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Terminal $record) => $record->isInactive())
                        ->requiresConfirmation()
                        ->modalHeading('Activar terminal')
                        ->modalDescription(fn (Terminal $record) => "La terminal \"{$record->name}\" volverá a estar disponible para marcaciones.")
                        ->modalSubmitActionLabel('Sí, activar')
                        ->action(function (Terminal $record) {
                            $record->update(['status' => 'active']);
                            Notification::make()->success()->title('Terminal activada')->send();
                        }),

                    Action::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn (Terminal $record) => $record->isActive())
                        ->requiresConfirmation()
                        ->modalHeading('Desactivar terminal')
                        ->modalDescription(fn (Terminal $record) => "La terminal \"{$record->name}\" dejará de aceptar marcaciones y mostrará una pantalla de fuera de servicio.")
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->action(function (Terminal $record) {
                            $record->update(['status' => 'inactive']);
                            Notification::make()->warning()->title('Terminal desactivada')->send();
                        }),

                    TerminalLinkActions::generateSetupLink(Action::class),
                    TerminalLinkActions::showSetupLink(Action::class),
                    TerminalLinkActions::openLinkWindow(Action::class),
                    TerminalLinkActions::closeLinkWindow(Action::class),
                    TerminalLinkActions::printSheet(Action::class),
                    ...TerminalCommandActions::actions(Action::class),

                    Action::make('revoke_token')
                        ->label('Desvincular dispositivo')
                        ->tooltip('Invalida el acceso del dispositivo a la sincronización offline — requerirá volver a vincular')
                        ->icon('heroicon-o-shield-exclamation')
                        ->color('danger')
                        ->visible(fn (Terminal $record) => TerminalPairingActions::canManage() && $record->hasActiveSyncToken())
                        ->requiresConfirmation()
                        ->modalHeading('Desvincular dispositivo')
                        ->modalDescription(fn (Terminal $record) => "El dispositivo vinculado al terminal \"{$record->name}\" perderá acceso a la sincronización offline de inmediato. Para volver a usarlo habrá que vincularlo de nuevo (por código o con un enlace de configuración).")
                        ->modalSubmitActionLabel('Sí, desvincular')
                        ->action(function (Terminal $record) {
                            $record->revokeSyncTokens();
                            Notification::make()
                                ->success()
                                ->title('Dispositivo desvinculado')
                                ->body('El terminal deberá vincularse de nuevo para volver a sincronizar.')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    TerminalLinkActions::openLinkWindowBulk(),
                    TerminalCommandActions::bulk(),
                    TerminalMonitoringActions::bulk(),
                    static::confirmExitBulkAction(),
                    DeleteBulkAction::make()
                        ->modalDescription('Esta acción no se puede deshacer. Las marcaciones ya registradas con los terminales seleccionados no se eliminan, pero perderán la referencia a qué dispositivo físico las generó.')
                        ->modalSubmitActionLabel('Sí, eliminar'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay terminales registradas')
            ->emptyStateDescription('Crea una terminal y configurá el dispositivo físico con su URL de acceso.')
            ->emptyStateIcon('heroicon-o-computer-desktop');
    }

    /**
     * Campos de monitoreo (umbral de desconexión y horario de vigilancia), compartidos por el formulario del
     * terminal y la acción masiva **Configurar monitoreo**.
     *
     * @return array<int, Component>
     */
    public static function monitoringFields(): array
    {
        return [
            TextInput::make('stale_after_minutes')
                ->label('Considerar desconectado tras')
                ->numeric()
                ->integer()
                ->minValue(Terminal::MIN_STALE_MINUTES)
                ->maxValue(10080)
                ->suffix('minutos')
                ->placeholder(fn () => 'Usa el umbral general ('.app(GeneralSettings::class)->terminal_stale_threshold_minutes.' min)')
                ->helperText('Mínimo '.Terminal::MIN_STALE_MINUTES.' minutos. Vacío = umbral general.')
                ->columnSpanFull(),

            ...static::watchWindowFields(),
        ];
    }

    /**
     * Campos del horario de vigilancia (días y rango de horas), compartidos por el formulario del
     * terminal, la acción masiva **Configurar monitoreo** y el horario de atención de la sucursal.
     *
     * @param  bool  $forBranch  Etiquetas del horario de atención de la sucursal en vez de las del terminal.
     * @return array<int, Component>
     */
    public static function watchWindowFields(bool $forBranch = false): array
    {
        return [
            CheckboxList::make('watch_days')
                ->label($forBranch ? 'Días de atención' : 'Días que se vigila')
                ->options(Terminal::WATCH_DAY_OPTIONS)
                ->columns(7)
                ->bulkToggleable()
                ->helperText($forBranch
                    ? 'Ninguno marcado = todos los días.'
                    : 'Ninguno marcado = todos los días (o los de la sucursal, si tiene horario).')
                ->columnSpanFull(),

            TimePicker::make('watch_from')
                ->label($forBranch ? 'Atiende desde' : 'Vigilar desde')
                ->seconds(false)
                ->native(false)
                ->requiredWith('watch_to')
                ->helperText('Hora de Asunción. Vacío = todo el día.'),

            TimePicker::make('watch_to')
                ->label($forBranch ? 'Atiende hasta' : 'Vigilar hasta')
                ->seconds(false)
                ->native(false)
                ->requiredWith('watch_from')
                ->helperText('Un rango que cruza la medianoche pertenece al día en que empieza.'),
        ];
    }

    /** Versión de la app que corre en el dispositivo, avisando si quedó desactualizada respecto del servidor. */
    public static function describeAppVersion(Terminal $record): ?string
    {
        $version = $record->device_report['app_version'] ?? null;

        return $version === null ? null : $version.($record->isAppOutdated() ? ' — desactualizada, recargar el terminal' : ' — al día');
    }

    /** Cómo corre el terminal (app instalada, pantalla completa o pestaña del navegador), si la pestaña está oculta y si hay service worker. */
    public static function describeInstallMode(Terminal $record): ?string
    {
        $report = $record->device_report ?? [];
        $kind = $record->displayKind();

        if ($kind === null) {
            return null;
        }

        $mode = Terminal::getDisplayModeLabels()[$report['display_mode'] ?? ''] ?? ($kind === 'app' ? 'Instalada como app' : 'En el navegador (sin instalar)');

        if ($record->reportedHidden()) {
            $mode .= ' · pestaña oculta';
        }

        return ($report['sw_active'] ?? true) ? $mode : $mode.' · sin service worker (no funciona offline)';
    }

    /** Batería informada: nivel y si está cargando. */
    /** Sistema operativo y navegador del dispositivo vinculado, a partir de su User-Agent. */
    public static function describeSystem(Terminal $record): ?string
    {
        $device = $record->activeDevice;
        $parts = array_filter([$device?->os, $device?->browser]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Memoria y núcleos del equipo, si el navegador los informa. */
    public static function describeHardware(Terminal $record): ?string
    {
        $report = $record->device_report ?? [];
        $parts = array_filter([
            isset($report['device_memory_gb']) ? $report['device_memory_gb'].' GB de memoria (aprox.)' : null,
            isset($report['cpu_cores']) ? $report['cpu_cores'].' núcleos' : null,
            $report['platform'] ?? null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Tipo de conexión, resolución de pantalla, idioma y zona horaria que informa el navegador. */
    public static function describeConnection(Terminal $record): ?string
    {
        $report = $record->device_report ?? [];
        $parts = array_filter([
            isset($report['connection_type']) ? 'Conexión '.strtoupper($report['connection_type']) : null,
            $report['screen'] ?? null ? 'Pantalla '.$report['screen'] : null,
            $report['language'] ?? null,
            $report['timezone'] ?? null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Ubicación del equipo móvil frente a su sucursal, en lenguaje del panel. */
    public static function describeLocation(Terminal $record): ?string
    {
        if (($record->device_report['location_error'] ?? null) === 'denied') {
            return 'Sin permiso de ubicación';
        }

        $location = $record->locationStatus();

        if ($location === null) {
            return $record->branch?->coordinates ? null : 'La sucursal no tiene coordenadas cargadas';
        }

        $text = $location['outside'] ? "Fuera: a {$location['distance_m']} m (radio de {$location['radius_m']} m)" : "En la sucursal (a {$location['distance_m']} m)";

        if (! $location['reliable']) {
            $text .= ' · lectura imprecisa';
        }

        return $text.($location['read_at'] ? ' · '.$location['read_at']->diffForHumans() : '');
    }

    public static function describeBattery(Terminal $record): ?string
    {
        $level = $record->reportedBatteryLevel();

        if ($level === null) {
            return null;
        }

        return $level.'%'.($record->reportedCharging() === null ? '' : ($record->reportedCharging() ? ' · cargando' : ' · sin cargador'));
    }

    /** Permiso de cámara en lenguaje del panel. */
    public static function describeCamera(Terminal $record): ?string
    {
        return match ($record->device_report['camera'] ?? null) {
            'granted' => 'Permitida',
            'denied' => 'Bloqueada: el terminal no puede identificar',
            'prompt' => 'Sin decidir: falta aceptar el permiso',
            'unavailable' => 'No disponible en este navegador',
            default => null,
        };
    }

    /** Si el terminal logró mantener la pantalla encendida (Wake Lock): sin eso la tablet puede dormirse y dejar de reportar. */
    public static function describeWakeLock(Terminal $record): ?string
    {
        return match ($record->device_report['wake_lock'] ?? null) {
            true => 'Activa',
            false => 'Inactiva: el equipo puede dormirse y dejar de marcar'.($record->wakeLockErrorLabel() ? " (motivo: {$record->wakeLockErrorLabel()})" : ''),
            default => null,
        };
    }

    /** Diferencia entre el reloj del servidor y el del dispositivo; avisa si pasa de un minuto. */
    public static function describeClockSkew(Terminal $record): ?string
    {
        $seconds = $record->device_report['clock_skew_seconds'] ?? null;

        if ($seconds === null) {
            return null;
        }

        $text = ($seconds > 0 ? '+' : '').$seconds.' s';

        return abs($seconds) > 60 ? $text.' — el reloj del dispositivo está desajustado' : $text;
    }

    /** Opciones del selector «Confirmar salida y descansos» (`inherit` = null, hereda Configuración General). */
    public static function confirmExitOptions(): array
    {
        return [
            'inherit' => 'Heredar (lo de Configuración General)',
            'yes' => 'Sí, pedir confirmación',
            'no' => 'No, registrar directamente',
        ];
    }

    /** Selector Heredar/Sí/No de `terminals.confirm_exit`: si la salida y los descansos piden confirmar en este terminal. */
    public static function confirmExitField(): Select
    {
        return Select::make('confirm_exit')
            ->label('Confirmar salida y descansos')
            ->options(static::confirmExitOptions())
            ->default('inherit')
            ->native(false)
            ->selectablePlaceholder(false)
            ->formatStateUsing(fn ($state): string => MobileMarkingFields::toOption($state))
            ->dehydrateStateUsing(fn ($state): ?bool => MobileMarkingFields::fromOption($state))
            ->helperText('Con «Sí», la salida y los descansos piden un toque para confirmar; con «No» se registran solos. La entrada siempre es automática.');
    }

    /** Texto del detalle: el valor efectivo y de dónde viene (propio o de Configuración General). */
    public static function describeConfirmExit(Terminal $record): string
    {
        $effective = $record->confirmExitEnabled() ? 'Sí' : 'No';

        return $record->confirm_exit === null ? "{$effective} (de Configuración General)" : $effective;
    }

    /** Acción masiva: fija si los terminales seleccionados piden confirmar la salida y los descansos. */
    public static function confirmExitBulkAction(): BulkAction
    {
        return BulkAction::make('set_confirm_exit')
            ->label('Confirmar salida y descansos')
            ->icon('heroicon-o-finger-print')
            ->color('info')
            ->authorize(fn (): bool => auth()->user()?->can('update_terminal') ?? false)
            ->form([
                Select::make('value')
                    ->label('Valor')
                    ->options(static::confirmExitOptions())
                    ->native(false)
                    ->required()
                    ->helperText('El cambio llega a cada terminal con su siguiente sincronización.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Confirmar salida y descansos')
            ->modalSubmitActionLabel('Sí, aplicar')
            ->action(function (Collection $records, array $data): void {
                $value = MobileMarkingFields::fromOption($data['value']);

                $records->each(fn (Terminal $terminal) => $terminal->update(['confirm_exit' => $value]));

                Notification::make()
                    ->success()
                    ->title('Terminales actualizados')
                    ->body("{$records->count()} terminal(es) con «".static::confirmExitOptions()[MobileMarkingFields::toOption($value)].'».')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Tiempo de identificación del equipo: lo habitual (mediana) y lo que tarda el 10 % más lento, con la cantidad
     * de identificaciones medidas. Avisa si el equipo es lento (`Terminal::SLOW_IDENTIFICATION_MS`).
     */
    public static function describeIdentification(Terminal $record): ?string
    {
        $timing = $record->identificationTiming();

        if ($timing === null) {
            return null;
        }

        $seconds = fn (int $ms): string => number_format($ms / 1000, 1, ',', '.').' s';
        $text = "{$seconds($timing['median_ms'])} habitual · {$seconds($timing['p90_ms'])} el 10 % más lento";

        if ($timing['samples'] > 0) {
            $text .= " (últimas {$timing['samples']})";
        }

        return $record->isIdentificationSlow()
            ? $text.' — lento: revisar el equipo (aceleración gráfica, memoria o navegador)'
            : $text;
    }

    /** Almacenamiento usado del dispositivo. */
    public static function describeStorage(Terminal $record): ?string
    {
        $used = $record->device_report['storage_used_mb'] ?? null;
        $quota = $record->device_report['storage_quota_mb'] ?? null;

        if ($used === null) {
            return null;
        }

        return $quota ? "{$used} MB de {$quota} MB" : "{$used} MB";
    }

    /**
     * Texto de cómo se vinculó el dispositivo actual, según la última vinculación
     * registrada en la bitácora: enlace de configuración, código aprobado por un
     * usuario, o ventana de vinculación (a nombre de quien la abrió).
     */
    public static function describeLinkOrigin(Terminal $record): string
    {
        $via = $record->events()->where('type', 'linked')->latest('id')->first()?->payload['via'] ?? null;

        if ($via === 'setup') {
            return 'Enlace de configuración';
        }

        if ($via === 'pairing') {
            $request = $record->pairingRequests()->where('status', 'claimed')->with('approvedBy')->latest('claimed_at')->first();
            $by = $request?->approvedBy?->name;

            if ($request?->auto_approved) {
                return 'Ventana de vinculación'.($by ? " (abierta por {$by})" : '');
            }

            return 'Código de emparejamiento'.($by ? " (aprobó {$by})" : '');
        }

        return 'Sin registro';
    }

    /**
     * Relaciones del recurso.
     */
    public static function getRelations(): array
    {
        return [
            PairingRequestsRelationManager::class,
            DevicesRelationManager::class,
            ReliabilityRelationManager::class,
            EventsRelationManager::class,
            CommandsRelationManager::class,
            AttendanceEventsRelationManager::class,
        ];
    }

    /**
     * Páginas del recurso.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTerminals::route('/'),
            'create' => Pages\CreateTerminal::route('/create'),
            'view' => Pages\ViewTerminal::route('/{record}'),
            'edit' => Pages\EditTerminal::route('/{record}/edit'),
        ];
    }

    /**
     * Badge de navegación: muestra el conteo de terminales inactivas.
     */
    public static function getNavigationBadge(): ?string
    {
        $inactive = Terminal::where('status', 'inactive')->count();

        return $inactive > 0 ? (string) $inactive : null;
    }

    /**
     * Color del badge de navegación.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
