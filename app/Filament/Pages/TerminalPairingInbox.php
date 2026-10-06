<?php

namespace App\Filament\Pages;

use App\Filament\Actions\TerminalPairingActions;
use App\Filament\Resources\TerminalResource;
use App\Models\TerminalPairingRequest;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bandeja de solicitudes de vinculación de terminales por código. Es una Page
 * (no un Resource) a propósito: un Resource exigiría permisos nuevos por la
 * convención de `BasePolicy`, y la gestión de terminales ya se protege con
 * `update_terminal`. Muestra por defecto las pendientes.
 */
class TerminalPairingInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Solicitudes de vinculación';

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Solicitudes de vinculación';

    protected static string $view = 'filament.pages.terminal-pairing-inbox';

    /**
     * Solo quienes ya pueden gestionar terminales, y solo si el módulo de marcación
     * biométrica está activo (el mismo flag que gatea `TerminalResource`).
     */
    public static function canAccess(): bool
    {
        return TerminalResource::isModuleEnabled() && TerminalPairingActions::canManage();
    }

    /** Cantidad de solicitudes pendientes y vigentes. */
    public static function getNavigationBadge(): ?string
    {
        $pending = TerminalPairingRequest::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TerminalPairingRequest::query()->with(['terminal.branch', 'approvedBy']))
            ->columns([
                TextColumn::make('terminal.name')
                    ->label('Terminal')
                    ->icon('heroicon-o-computer-desktop')
                    ->description(fn (TerminalPairingRequest $record) => $record->terminal?->branch?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'terminal',
                        fn ($q) => $q->where('name', 'like', "%{$search}%")
                    )),

                TextColumn::make('effective_status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TerminalPairingRequest::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => TerminalPairingRequest::getStatusColors()[$state] ?? 'gray'),

                TextColumn::make('replaces_active_device')
                    ->label('Reemplazo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Reemplaza dispositivo' : 'Primer vínculo')
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),

                TextColumn::make('device_model_hint')
                    ->label('Dispositivo')
                    ->placeholder('Modelo no detectado')
                    ->description(fn (TerminalPairingRequest $record) => $record->ip_address ? 'IP '.$record->ip_address : null)
                    ->tooltip(fn (TerminalPairingRequest $record) => $record->user_agent),

                TextColumn::make('created_at')
                    ->label('Solicitada')
                    ->since()
                    ->sortable(),

                TextColumn::make('approvedBy.name')
                    ->label('Aprobada por')
                    ->placeholder('Sin aprobar'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Estado')
                    ->options(['pending' => 'Pendientes', 'resolved' => 'Resueltas o vencidas'])
                    ->default('pending')
                    ->native(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pending' => $query->pending(),
                        'resolved' => $query->where(fn ($q) => $q->where('status', '!=', TerminalPairingRequest::STATUS_PENDING)->orWhere('expires_at', '<=', now())),
                        default => $query,
                    }),
            ])
            ->actions([
                TerminalPairingActions::approve(),
                TerminalPairingActions::deny(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->poll('15s')
            ->emptyStateHeading('Sin solicitudes de vinculación')
            ->emptyStateDescription('Cuando un dispositivo pida vincularse a un terminal, la solicitud aparecerá acá.')
            ->emptyStateIcon('heroicon-o-link');
    }
}
