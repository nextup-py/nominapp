<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TerminalResource\Pages;
use App\Filament\Resources\TerminalResource\RelationManagers\AttendanceEventsRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Company;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 6;

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
                            // Excluye sucursales de empresas inactivas de las opciones — pero
                            // nunca de la sucursal YA asignada al editar: modifyQueryUsing()
                            // también filtra la query que resuelve la etiqueta del valor
                            // actual (Select::getSelectedRecordUsing()), así que sin el OR con
                            // $record?->branch_id, editar un terminal cuya empresa se
                            // desactivó después dejaría el campo en blanco.
                            ->relationship('branch', 'name', modifyQueryUsing: fn (Builder $query, ?Terminal $record) => $query
                                ->where(function (Builder $query) use ($record) {
                                    $query->whereHas('company', fn (Builder $query) => $query->active());

                                    if ($record?->branch_id) {
                                        $query->orWhere('id', $record->branch_id);
                                    }
                                }))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Terminal::getStatusOptions())
                            ->helperText('Un terminal inactivo no puede marcar asistencia, aunque conserve su token de sincronización.')
                            ->native(false)
                            ->default('active')
                            ->required(),
                    ])
                    ->columns(3),

                Section::make('Dispositivo')
                    ->icon('heroicon-o-device-tablet')
                    ->compact()
                    ->schema([
                        TextInput::make('device_brand')
                            ->label('Marca')
                            ->placeholder('Ej: Samsung, Apple, Lenovo')
                            ->helperText('Se sugiere automáticamente al vincular el dispositivo, cuando el navegador lo permite. Editable.')
                            ->maxLength(60),

                        TextInput::make('device_model')
                            ->label('Modelo')
                            ->placeholder('Ej: Galaxy Tab A8')
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
                            ->placeholder('Ej: Pantalla con rayón en esquina superior derecha')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Instalación')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->compact()
                    ->schema([
                        DatePicker::make('installed_at')
                            ->label('Fecha de instalación')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),

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

                        TextEntry::make('last_seen_at')
                            ->label('Última carga de página')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Sin actividad registrada')
                            ->since(),
                    ]),

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
                    ->tooltip('Basado en el último heartbeat exitoso de sincronización offline')
                    ->formatStateUsing(fn (string $state) => Terminal::getConnectivityStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => Terminal::getConnectivityStatusColors()[$state] ?? 'gray'),

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

                        $threshold = now()->subHours(app(GeneralSettings::class)->terminal_stale_threshold_hours);

                        return match ($data['value']) {
                            'never_connected' => $query->whereNull('last_heartbeat_at'),
                            'online' => $query->where('last_heartbeat_at', '>=', $threshold),
                            'stale' => $query->whereNotNull('last_heartbeat_at')->where('last_heartbeat_at', '<', $threshold),
                            default => $query,
                        };
                    }),

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

                    Action::make('view_setup_link')
                        ->label('Ver enlace de configuración')
                        ->tooltip('Ver el enlace/QR de un solo uso todavía vigente, sin invalidarlo')
                        ->icon('heroicon-o-qr-code')
                        ->color('gray')
                        ->visible(fn (Terminal $record) => static::hasValidSetupLink($record))
                        ->modalHeading('Enlace de configuración del terminal')
                        ->modalContent(fn (Terminal $record) => static::renderCurrentSetupLinkModal($record))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar'),

                    // "Generar enlace" (sin enlace vigente): un solo paso, sin confirmación —
                    // abre el modal y genera+muestra el QR de inmediato, como ya funcionaba.
                    Action::make('generate_setup_link')
                        ->label('Generar enlace de configuración')
                        ->tooltip('Enlace/QR de un solo uso para vincular el dispositivo a la sincronización offline')
                        ->icon('heroicon-o-qr-code')
                        ->color('gray')
                        ->visible(fn (Terminal $record) => ! static::hasValidSetupLink($record))
                        ->modalHeading('Enlace de configuración del terminal')
                        ->modalContent(fn (Terminal $record) => static::renderSetupLinkModal($record))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Cerrar'),

                    // "Generar nuevo enlace" (ya hay uno vigente): pide confirmación explícita
                    // ANTES de generar — modalContent() se evalúa (con su efecto colateral) al
                    // abrir el modal, no al confirmar, así que no se puede mostrar el QR nuevo
                    // en el mismo paso sin invalidar el vigente antes de que el admin decida.
                    // Genera y avisa por notificación; el QR nuevo se ve con "Ver enlace".
                    Action::make('regenerate_setup_link')
                        ->label('Generar nuevo enlace de configuración')
                        ->tooltip('Invalida el enlace vigente y genera uno nuevo')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->visible(fn (Terminal $record) => static::hasValidSetupLink($record))
                        ->requiresConfirmation()
                        ->modalHeading('¿Generar un enlace nuevo?')
                        ->modalDescription('Ya existe un enlace de configuración vigente para este terminal. Generar uno nuevo invalida el anterior de inmediato, aunque todavía no haya sido usado.')
                        ->modalSubmitActionLabel('Sí, generar uno nuevo')
                        ->action(function (Terminal $record) {
                            $record->generateSetupToken(30);
                            Notification::make()
                                ->success()
                                ->title('Enlace nuevo generado')
                                ->body('El enlace anterior quedó invalidado. Usá "Ver enlace de configuración" para verlo.')
                                ->send();
                        }),

                    Action::make('revoke_token')
                        ->label('Revocar token')
                        ->tooltip('Invalida el acceso del terminal a la sincronización offline — requerirá re-provisión')
                        ->icon('heroicon-o-shield-exclamation')
                        ->color('danger')
                        ->visible(fn (Terminal $record) => $record->tokens()->exists())
                        ->requiresConfirmation()
                        ->modalHeading('Revocar token de sincronización')
                        ->modalDescription(fn (Terminal $record) => "El terminal \"{$record->name}\" perderá acceso a la API de sincronización offline de inmediato. Deberá re-provisionarse con un nuevo enlace de configuración antes de volver a sincronizar.")
                        ->modalSubmitActionLabel('Sí, revocar')
                        ->action(function (Terminal $record) {
                            $record->revokeSyncTokens();
                            Notification::make()
                                ->success()
                                ->title('Token revocado')
                                ->body('El terminal deberá re-provisionarse para volver a sincronizar.')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
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
     * Indica si el terminal tiene un enlace de configuración vigente sin
     * reclamar — determina si la acción de tabla/header muestra "Ver enlace"
     * (no destructivo) o "Generar enlace" (invalida el vigente, si lo hay).
     */
    public static function hasValidSetupLink(Terminal $record): bool
    {
        return $record->setup_token !== null
            && $record->setup_token_expires_at?->isFuture();
    }

    /**
     * Genera un nuevo token de configuración de un solo uso para el terminal y
     * renderiza el modal con su URL/QR. Público porque lo usan tanto la acción
     * de tabla (`ListTerminals`) como el header action equivalente en
     * `ViewTerminal`. Efecto colateral intencional dentro de un closure de
     * contenido: si Livewire re-evalúa el modal más de una vez mientras está
     * abierto, cada llamada genera Y muestra el token vigente en ese momento
     * de forma consistente (nunca queda desincronizado con lo que se ve en
     * pantalla) — a costa de invalidar tokens de configuración
     * previos no usados, lo cual es aceptable para un enlace de un solo uso.
     */
    public static function renderSetupLinkModal(Terminal $record): View
    {
        $setupToken = $record->generateSetupToken(30);
        $url = route('terminal.setup.show', ['code' => $record->code, 'setupToken' => $setupToken]);
        $expiresAt = $record->setup_token_expires_at;

        return view('filament.modals.terminal-setup-link', compact('url', 'expiresAt'));
    }

    /**
     * Muestra el enlace de configuración YA vigente sin generar uno nuevo —
     * a diferencia de `renderSetupLinkModal()`, no tiene efecto colateral.
     * Solo debe usarse cuando `hasValidSetupLink()` es true (la acción que la
     * invoca controla esa visibilidad).
     */
    public static function renderCurrentSetupLinkModal(Terminal $record): View
    {
        $url = route('terminal.setup.show', ['code' => $record->code, 'setupToken' => $record->setup_token]);
        $expiresAt = $record->setup_token_expires_at;

        return view('filament.modals.terminal-setup-link', compact('url', 'expiresAt'));
    }

    /**
     * Relaciones del recurso.
     */
    public static function getRelations(): array
    {
        return [
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
