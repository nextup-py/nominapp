<?php

namespace App\Filament\Pages;

use AchyutN\FilamentLogViewer\Enums\LogLevel;
use AchyutN\FilamentLogViewer\Model\Log;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Components\Tab;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/** Visor de logs de la aplicación con filtros, búsqueda y detalle de stack trace. */
class LogViewer extends Page implements HasTable
{
    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    use InteractsWithTable;

    protected static ?string $slug = 'logs';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Visor de Logs';

    protected static ?string $title = 'Visor de Logs';

    protected static ?int $navigationSort = 9999;

    protected static string $view = 'filament.pages.log-viewer';

    #[Url(except: null)]
    public ?string $activeTab = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(Log::query())
            ->modifyQueryUsing(function (Builder $query): void {
                if ($this->activeTab) {
                    $query->where('log_level', $this->activeTab);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('log_level')
                    ->label('Nivel')
                    ->badge(),
                Tables\Columns\TextColumn::make('env')
                    ->label('Entorno')
                    ->color(fn (string $state): array => match ($state) {
                        'local' => Color::Blue,
                        'production' => Color::Red,
                        'staging' => Color::Orange,
                        'testing' => Color::Gray,
                        default => Color::Yellow,
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->badge(),
                Tables\Columns\TextColumn::make('file')
                    ->label('Archivo')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('message')
                    ->label('Mensaje')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('date')
                    ->label('Fecha')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver detalle')
                    ->modalHeading('Detalle del log')
                    ->infolist([
                        TextEntry::make('_sin_stack')
                            ->getStateUsing(fn () => 'Sin stack trace disponible para este registro.')
                            ->hiddenLabel()
                            ->visible(fn ($record): bool => empty($record->stack)),
                        RepeatableEntry::make('stack')
                            ->hiddenLabel()
                            ->visible(fn ($record): bool => ! empty($record->stack))
                            ->schema([
                                TextEntry::make('trace')
                                    ->hiddenLabel()
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->slideOver(),
            ])
            ->filters([
                Filter::make('date')
                    ->label('Rango de fechas')
                    ->form([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, string $date) => $q->whereDate('date', '>=', $date))
                        ->when($data['until'], fn (Builder $q, string $date) => $q->whereDate('date', '<=', $date))
                    )
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (! empty($data['from']) && ! empty($data['until'])) {
                            $indicators[] = Indicator::make('Logs del '.Carbon::parse($data['from'])->format('d/m/Y').' al '.Carbon::parse($data['until'])->format('d/m/Y'))
                                ->removeField('from')
                                ->removeField('until');
                        } elseif (! empty($data['from'])) {
                            $indicators[] = Indicator::make('Logs desde '.Carbon::parse($data['from'])->format('d/m/Y'))
                                ->removeField('from');
                        } elseif (! empty($data['until'])) {
                            $indicators[] = Indicator::make('Logs hasta '.Carbon::parse($data['until'])->format('d/m/Y'))
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->defaultSort('date', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin logs registrados')
            ->emptyStateDescription('No hay entradas en los archivos de log.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getCachedTabs(): array
    {
        return $this->getTabs();
    }

    /**
     * Tabs de filtrado por nivel de log.
     *
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $all = [
            null => Tab::make('Todos')
                ->badge(fn (): ?int => Log::query()->count() ?: null),
        ];

        $labelEs = [
            'alert' => 'Alerta',
            'critical' => 'Crítico',
            'debug' => 'Debug',
            'emergency' => 'Emergencia',
            'error' => 'Error',
            'info' => 'Info',
            'notice' => 'Aviso',
            'warning' => 'Advertencia',
        ];

        $tabs = collect(LogLevel::cases())
            ->mapWithKeys(fn (LogLevel $level): array => [
                $level->value => Tab::make($labelEs[$level->value] ?? $level->getLabel())
                    ->badge(fn (): ?int => Log::query()->where('log_level', $level)->count() ?: null)
                    ->badgeColor($level->getColor()),
            ])->toArray();

        return array_merge($all, $tabs);
    }

    /**
     * Retorna los archivos .log disponibles en storage/logs/ como opciones para el Select.
     *
     * @return array<string, string>
     */
    protected function getLogFileOptions(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        $options = [];

        foreach ($files as $path) {
            $name = basename($path);
            $size = round(filesize($path) / 1024, 1);
            $options[$name] = "{$name} ({$size} KB)";
        }

        arsort($options);

        return $options;
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('descargar_log')
                ->label('Descargar log')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => ! empty($this->getLogFileOptions()))
                ->form([
                    Select::make('file')
                        ->label('Archivo de log')
                        ->options(fn (): array => $this->getLogFileOptions())
                        ->required()
                        ->placeholder('Seleccionar archivo'),
                ])
                ->modalHeading('Descargar archivo de log')
                ->modalSubmitActionLabel('Descargar')
                ->action(function (array $data): void {
                    $url = route('logs.download', ['file' => $data['file']]);
                    $this->js("window.open('{$url}', '_blank')");
                }),
            Action::make('limpiar_logs')
                ->visible(Log::query()->count() > 0)
                ->label('Limpiar logs')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Limpiar todos los logs?')
                ->modalDescription('Esta acción vaciará todos los archivos de log. No se puede deshacer.')
                ->modalSubmitActionLabel('Sí, limpiar')
                ->action(function (): void {
                    Log::destroyAllLogs();
                    Notification::make()
                        ->title('Logs eliminados correctamente')
                        ->success()
                        ->send();
                }),
        ];
    }
}
