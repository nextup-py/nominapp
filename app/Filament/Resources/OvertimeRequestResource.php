<?php

namespace App\Filament\Resources;

use App\Filament\Actions\OvertimeRequestActions;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\OvertimeRequestResource\Pages;
use App\Models\AttendanceEvent;
use App\Models\OvertimeRequest;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pedidos previos de horas extra, para RR.HH.: listado de todos los pedidos con su estado y las acciones de
 * aprobar, rechazar y cancelar. Los jefes piden y aprueban desde «Mi equipo».
 */
class OvertimeRequestResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = OvertimeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'pedido de horas extra';

    protected static ?string $pluralModelLabel = 'Pedidos de horas extra';

    protected static ?string $navigationLabel = 'Pedidos de horas extra';

    protected static ?string $slug = 'pedidos-horas-extra';

    /** Insignia: pedidos que esperan la decisión de RR.HH. */
    public static function getNavigationBadge(): ?string
    {
        $count = OvertimeRequest::query()->where('status', OvertimeRequest::STATUS_PENDING_HR)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /** Los pedidos se crean con la acción «Pedir horas extra» y no se editan: se cancelan y se piden de nuevo. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'requestedBy', 'requestedByEmployee']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('employee.first_name')
                    ->label('Empleado')
                    ->formatStateUsing(fn ($state, OvertimeRequest $record): string => trim(($record->employee?->first_name ?? '').' '.($record->employee?->last_name ?? '')))
                    ->description(fn (OvertimeRequest $record): ?string => $record->employee ? 'CI: '.$record->employee->ci : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('employee', fn (Builder $q) => $q
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('ci', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('date_from')
                    ->label('Fechas')
                    ->formatStateUsing(fn ($state, OvertimeRequest $record): string => $record->rangeLabel())
                    ->description(fn (OvertimeRequest $record): ?string => $record->windowLabel())
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_hours')->label('Máx. por día')->suffix(' h'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Motivo')
                    ->formatStateUsing(fn ($state): string => AttendanceEvent::overtimeReasonLabel($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OvertimeRequest::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => OvertimeRequest::getStatusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('requestedBy.name')->label('Pidió')->formatStateUsing(fn ($state, OvertimeRequest $record): ?string => $record->requesterName())->placeholder('Sin dato'),
                Tables\Columns\TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(OvertimeRequest::getStatusLabels()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    OvertimeRequestActions::approve(Tables\Actions\Action::class),
                    OvertimeRequestActions::reject(Tables\Actions\Action::class),
                    OvertimeRequestActions::cancel(Tables\Actions\Action::class),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin pedidos de horas extra')
            ->emptyStateDescription('Los pedidos que hagan los jefes o RR.HH. aparecen acá.')
            ->emptyStateIcon('heroicon-o-clock')
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Pedido')->columns(3)->schema([
                TextEntry::make('employee.first_name')
                    ->label('Empleado')
                    ->formatStateUsing(fn ($state, OvertimeRequest $record): string => trim(($record->employee?->first_name ?? '').' '.($record->employee?->last_name ?? ''))),
                TextEntry::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => OvertimeRequest::getStatusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => OvertimeRequest::getStatusColors()[$state] ?? 'gray'),
                TextEntry::make('date_from')->label('Fechas')->formatStateUsing(fn ($state, OvertimeRequest $record): string => $record->rangeLabel()),
                TextEntry::make('max_hours')->label('Horas máximas por día')->suffix(' h'),
                TextEntry::make('time_from')->label('Ventana horaria')->formatStateUsing(fn ($state, OvertimeRequest $record): string => $record->windowLabel() ?? 'Sin ventana'),
                TextEntry::make('reason')->label('Motivo')->formatStateUsing(fn ($state): string => AttendanceEvent::overtimeReasonLabel($state)),
                TextEntry::make('notes')->label('Detalle')->placeholder('Sin detalle')->columnSpanFull(),
            ]),
            InfoSection::make('Seguimiento')->columns(3)->schema([
                TextEntry::make('requestedBy.name')->label('Pidió')->formatStateUsing(fn ($state, OvertimeRequest $record): ?string => $record->requesterName())->placeholder('Sin dato'),
                TextEntry::make('boss_approved_at')->label('Aprobó el jefe')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('hr_approved_at')->label('Aprobó RR.HH.')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('auto_approved')->label('Aprobación automática')->formatStateUsing(fn ($state): string => $state ? 'Sí, por el tope de horas' : 'No'),
                TextEntry::make('escalated_at')->label('Escalado a RR.HH.')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('regularization')->label('Regularización')->formatStateUsing(fn ($state): string => $state ? 'Sí (horas extra de hoy ya hechas)' : 'No'),
                TextEntry::make('rejected_at')->label('Rechazado')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('rejection_reason')->label('Motivo del rechazo')->placeholder('—')->columnSpan(2),
                TextEntry::make('cancelled_at')->label('Cancelado')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOvertimeRequests::route('/'),
            'view' => Pages\ViewOvertimeRequest::route('/{record}'),
        ];
    }
}
