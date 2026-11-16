<?php

namespace App\Filament\Resources\TerminalResource\RelationManagers;

use App\Models\TerminalDevice;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial de solo lectura de los dispositivos que estuvieron vinculados al terminal: cuándo entró cada uno, desde
 * qué IP, con qué sistema y navegador, hasta cuándo estuvo en línea y por qué salió. Un terminal admite un solo
 * dispositivo a la vez, así que este historial es lo que permite ver que dos equipos se estaban disputando el mismo.
 */
class DevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'devices';

    protected static ?string $title = 'Dispositivos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(fn (TerminalDevice $record) => $record->isActive() ? 'Vinculado ahora' : (TerminalDevice::getReasonLabels()[$record->unlink_reason] ?? 'Desvinculado'))
                    ->color(fn (TerminalDevice $record) => $record->isActive() ? 'success' : ($record->unlink_reason === TerminalDevice::REASON_REPLACED ? 'warning' : 'gray')),

                TextColumn::make('device_label')
                    ->label('Dispositivo')
                    ->getStateUsing(fn (TerminalDevice $record) => $record->deviceLabel())
                    ->placeholder('Sin identificar'),

                TextColumn::make('os')->label('Sistema')->placeholder('No detectado'),

                TextColumn::make('browser')->label('Navegador')->placeholder('No detectado'),

                TextColumn::make('ip_address')->label('IP')->placeholder('Sin dato'),

                TextColumn::make('device_id')
                    ->label('ID')
                    ->getStateUsing(fn (TerminalDevice $record) => $record->shortDeviceId())
                    ->tooltip(fn (TerminalDevice $record) => $record->device_id)
                    ->placeholder('Aún no informado')
                    ->fontFamily('mono'),

                TextColumn::make('linked_at')->label('Vinculado')->dateTime('d/m/Y H:i')->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Última vez en línea')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin dato'),

                TextColumn::make('unlinked_at')
                    ->label('Salió')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sigue vinculado')
                    ->description(fn (TerminalDevice $record) => $record->unlinkedBy?->name ? 'Por '.$record->unlinkedBy->name : null),

                TextColumn::make('linked_via')
                    ->label('Vía')
                    ->formatStateUsing(fn (string $state) => $state === 'setup' ? 'Enlace de configuración' : 'Código de emparejamiento')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Vinculación')
                    ->placeholder('Todos')
                    ->trueLabel('Solo el vinculado ahora')
                    ->falseLabel('Solo los anteriores')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('unlinked_at'),
                        false: fn (Builder $query) => $query->whereNotNull('unlinked_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('unlinkedBy'))
            ->actions([])
            ->bulkActions([])
            ->defaultSort('linked_at', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin dispositivos registrados')
            ->emptyStateDescription('Cada dispositivo que se vincula a este terminal queda registrado acá, con el que reemplazó.')
            ->emptyStateIcon('heroicon-o-device-phone-mobile');
    }
}
