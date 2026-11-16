<?php

namespace App\Filament\Resources\TerminalResource\RelationManagers;

use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Terminal;
use App\Models\TerminalDailyStat;
use App\Services\TerminalReliabilityService;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Confiabilidad del terminal día por día (solo lectura): disponibilidad, cortes, marcaciones, conflictos y calidad de
 * identificación, en el rango de fechas que elija quien mira (por defecto, los últimos 30 días). El resumen del
 * período va sobre la tabla. Los datos salen de `terminal_daily_stats` (ver `TerminalReliabilityService`).
 */
class ReliabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'dailyStats';

    protected static ?string $title = 'Confiabilidad';

    /** Días del rango que se propone al abrir la pestaña. */
    private const DEFAULT_RANGE_DAYS = 30;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $threshold = (int) app(GeneralSettings::class)->terminal_min_availability_percent;

        return $table
            ->description(fn () => $this->summaryText())
            ->columns([
                TextColumn::make('date')->label('Día')->date('d/m/Y')->sortable(),

                TextColumn::make('availability')
                    ->label('Disponibilidad')
                    ->getStateUsing(fn (TerminalDailyStat $record) => $record->availabilityPercent())
                    ->formatStateUsing(fn ($state) => $state === null ? 'Sin datos' : "{$state}%")
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= $threshold => 'success',
                        $state >= $threshold - 10 => 'warning',
                        default => 'danger',
                    })
                    ->tooltip('Porcentaje del horario de vigilancia en que el terminal estuvo en línea.'),

                TextColumn::make('outages_count')->label('Cortes')->alignCenter(),

                TextColumn::make('outage_minutes')
                    ->label('Tiempo sin conexión')
                    ->formatStateUsing(fn (int $state) => self::duration($state)),

                TextColumn::make('longest_outage_minutes')
                    ->label('Corte más largo')
                    ->formatStateUsing(fn (int $state) => self::duration($state)),

                TextColumn::make('marks_count')->label('Marcaciones')->alignCenter(),

                TextColumn::make('conflicts_count')
                    ->label('Conflictos')
                    ->alignCenter()
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : null)
                    ->tooltip('Marcaciones de este terminal que el servidor rechazó al sincronizar (quedan en Fallos de Marcación).'),

                TextColumn::make('identification_failure')
                    ->label('No reconocidos')
                    ->getStateUsing(fn (TerminalDailyStat $record) => $record->identificationFailurePercent())
                    ->formatStateUsing(fn ($state) => $state === null ? 'Sin datos' : "{$state}%")
                    ->tooltip('Intentos de identificación en que la cámara no reconoció a nadie, sobre el total de intentos.'),

                TextColumn::make('identification_average')
                    ->label('Tiempo de identificación')
                    ->getStateUsing(fn (TerminalDailyStat $record) => $record->averageIdentificationMs())
                    ->formatStateUsing(fn ($state) => $state === null ? 'Sin datos' : number_format($state / 1000, 1, ',', '.').' s'),
            ])
            ->filters([
                Filter::make('range')
                    ->label('Período')
                    ->form([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->closeOnDateSelection()->default(now()->subDays(self::DEFAULT_RANGE_DAYS - 1)),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->closeOnDateSelection()->default(now()),
                    ])
                    ->columns(2)
                    ->default(['from' => now()->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString(), 'until' => now()->toDateString()])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where('terminal_daily_stats.date', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('terminal_daily_stats.date', '<=', $until)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Desde '.Carbon::parse($data['from'])->format('d/m/Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Hasta '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->select('terminal_daily_stats.*')->addSelect([
                'marks_count' => AttendanceEvent::query()->selectRaw('count(*)')
                    ->whereColumn('attendance_events.terminal_id', 'terminal_daily_stats.terminal_id')
                    ->whereColumn('attendance_events.recorded_at', '>=', 'terminal_daily_stats.date')
                    ->whereRaw('attendance_events.recorded_at < DATE_ADD(terminal_daily_stats.date, INTERVAL 1 DAY)'),
                'conflicts_count' => AttendanceMarkFailure::query()->selectRaw('count(*)')
                    ->where('failure_type', 'sync_conflict')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(attendance_mark_failures.metadata, '$.terminal_id')) = CAST(terminal_daily_stats.terminal_id AS CHAR)")
                    ->whereColumn('attendance_mark_failures.occurred_at', '>=', 'terminal_daily_stats.date')
                    ->whereRaw('attendance_mark_failures.occurred_at < DATE_ADD(terminal_daily_stats.date, INTERVAL 1 DAY)'),
            ]))
            ->actions([])
            ->bulkActions([])
            ->defaultSort('date', 'desc')
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('Sin datos de confiabilidad')
            ->emptyStateDescription('El resumen se arma cada minuto mientras el terminal está vinculado y dentro de su horario de vigilancia. Ajustá el período si no ves días.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }

    /** Resumen del período elegido: lo mismo que va en el Excel, para el terminal que se está mirando. */
    private function summaryText(): string
    {
        /** @var Terminal $terminal */
        $terminal = $this->getOwnerRecord();
        $filter = $this->tableFilters['range'] ?? [];
        $from = Carbon::parse($filter['from'] ?? now()->subDays(self::DEFAULT_RANGE_DAYS - 1));
        $until = Carbon::parse($filter['until'] ?? now());

        if ($from->gt($until)) {
            return 'El período elegido es inválido: la fecha de inicio es posterior a la de fin.';
        }

        $summary = app(TerminalReliabilityService::class)->summary($terminal, $from, $until);

        $availability = $summary['availability'] === null ? 'sin datos' : "{$summary['availability']}%";
        $failure = $summary['identification_failure_percent'] === null ? 'sin datos' : "{$summary['identification_failure_percent']}% no reconocidos";
        $average = $summary['average_identification_ms'] === null ? 'sin datos' : number_format($summary['average_identification_ms'] / 1000, 1, ',', '.').' s';

        return "Del {$from->format('d/m/Y')} al {$until->format('d/m/Y')}: disponibilidad {$availability}, {$summary['outages']} cortes"
            .($summary['outages'] > 0 ? ' (el más largo, '.self::duration($summary['longest_outage_minutes']).')' : '')
            .", {$summary['marks']} marcaciones, {$summary['conflicts']} conflictos, identificación {$average} ({$failure}).";
    }

    /** «1 h 20 min» a partir de minutos; «0 min» si no hubo. */
    public static function duration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours > 0 ? trim("{$hours} h".($rest > 0 ? " {$rest} min" : '')) : "{$rest} min";
    }
}
