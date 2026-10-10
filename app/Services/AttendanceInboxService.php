<?php

namespace App\Services;

use App\Filament\Resources\AbsenceResource;
use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\AttendanceMarkFailureResource;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceMarkFailure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reúne en un solo lugar lo que RR.HH. tiene por resolver en asistencia: jornadas sin salida,
 * tardanzas y horas extra por aprobar, ausencias por revisar y fallos de marcación pendientes.
 * Las condiciones de las jornadas se reutilizan de `AttendanceDay` (única fuente de verdad).
 */
class AttendanceInboxService
{
    /**
     * Secciones de la bandeja visibles para el usuario actual, con su cantidad y la fecha más antigua.
     *
     * @return array<int, array{key: string, label: string, description: string, icon: string, color: string, count: int, oldest: Carbon|null, url: string}>
     */
    public static function sections(): array
    {
        $sections = [];

        if (AttendanceDayResource::canViewAny()) {
            $sections[] = self::section(
                'missing_check_out', 'Jornadas sin salida', 'Entraron y no marcaron salida: hay que corregirlas para que se calculen las horas.',
                'heroicon-o-arrow-right-start-on-rectangle', 'danger',
                self::recentDays()->whereRaw(AttendanceDay::missingCheckOutSql(), AttendanceDay::missingCheckOutBindings()),
                'date',
                AttendanceDayResource::getUrl('index', ['activeTab' => 'all', 'tableFilters' => ['missing_check_out' => ['isActive' => true]]]),
            );
            $sections[] = self::section(
                'tardiness', 'Tardanzas por aprobar', 'Minutos de atraso que RR.HH. aún no decidió si se descuentan en nómina.',
                'heroicon-o-clock', 'warning',
                self::recentDays()->whereRaw(AttendanceDay::PENDING_TARDINESS_SQL),
                'date',
                AttendanceDayResource::getUrl('index', ['activeTab' => 'attention']),
            );
            $sections[] = self::section(
                'overtime', 'Horas extra por aprobar', 'Horas sobre el horario pendientes de aprobación; solo las aprobadas se pagan.',
                'heroicon-o-plus-circle', 'warning',
                self::recentDays()->whereRaw(AttendanceDay::PENDING_OVERTIME_SQL),
                'date',
                AttendanceDayResource::getUrl('index', ['activeTab' => 'attention']),
            );
        }

        if (AbsenceResource::canViewAny()) {
            $sections[] = self::section(
                'absences', 'Ausencias por revisar', 'Ausencias detectadas que RR.HH. todavía no justificó ni marcó como injustificadas.',
                'heroicon-o-user-minus', 'warning',
                Absence::query()->where('status', 'pending'),
                'created_at',
                AbsenceResource::getUrl('index', ['activeTab' => 'pending']),
            );
        }

        if (AttendanceMarkFailureResource::canViewAny()) {
            $sections[] = self::section(
                'mark_failures', 'Fallos de marcación', 'Marcaciones que el terminal o el celular rechazaron y esperan una decisión.',
                'heroicon-o-exclamation-triangle', 'danger',
                AttendanceMarkFailure::query()->where('resolution_status', 'pending'),
                'occurred_at',
                AttendanceMarkFailureResource::getUrl('index', ['tableFilters' => ['resolution_status' => ['value' => 'pending']]]),
            );
        }

        return $sections;
    }

    /** Total de pendientes entre las secciones visibles (insignia del menú). */
    public static function totalPending(): int
    {
        return array_sum(array_column(self::sections(), 'count'));
    }

    /** Jornadas dentro de la ventana de atención (`AttendanceDay::ATTENTION_WINDOW_DAYS`). */
    private static function recentDays(): Builder
    {
        return AttendanceDay::query()->where('attendance_days.date', '>=', AttendanceDay::attentionWindowStart());
    }

    /**
     * @return array{key: string, label: string, description: string, icon: string, color: string, count: int, oldest: Carbon|null, url: string}
     */
    private static function section(string $key, string $label, string $description, string $icon, string $color, Builder $query, string $dateColumn, string $url): array
    {
        $stats = (clone $query)->selectRaw("count(*) as total, min({$dateColumn}) as oldest")->toBase()->first();

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
            'color' => $color,
            'count' => (int) ($stats->total ?? 0),
            'oldest' => filled($stats->oldest ?? null) ? Carbon::parse($stats->oldest) : null,
            'url' => $url,
        ];
    }
}
