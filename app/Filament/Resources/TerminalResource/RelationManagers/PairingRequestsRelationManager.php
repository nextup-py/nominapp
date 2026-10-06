<?php

namespace App\Filament\Resources\TerminalResource\RelationManagers;

use App\Filament\Actions\TerminalPairingActions;
use App\Models\TerminalPairingRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historial de solicitudes de vinculación del terminal, con las acciones de
 * aprobar/rechazar para las pendientes. Comparte las acciones con la bandeja
 * global (`TerminalPairingInbox`).
 */
class PairingRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'pairingRequests';

    protected static ?string $title = 'Solicitudes de vinculación';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
            ->actions([
                TerminalPairingActions::approve(),
                TerminalPairingActions::deny(),
            ])
            ->headerActions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin solicitudes de vinculación')
            ->emptyStateDescription('Las solicitudes de dispositivos que pidan vincularse a este terminal aparecerán acá.')
            ->emptyStateIcon('heroicon-o-link');
    }
}
