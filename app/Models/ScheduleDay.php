<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleDay extends Model
{
    protected $fillable = [
        'schedule_id',
        'day_of_week',
        'is_active',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relación con el horario al que pertenece este día
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    // Relación con los descansos de este día
    public function breaks(): HasMany
    {
        return $this->hasMany(ScheduleBreak::class);
    }

    /**
     * Retorna el mapeo de número de día a nombre en español.
     *
     * @return array<int, string>
     */
    public static function getDayOptions(): array
    {
        return [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];
    }

    /**
     * Accesor para obtener el nombre del día de la semana.
     */
    public function getDayOfWeekNameAttribute(): string
    {
        return static::getDayOptions()[$this->day_of_week] ?? 'Desconocido';
    }

    // Calcular las horas programadas para este día
    public function getScheduledHoursAttribute(): float
    {
        // Calcular la duración total entre start_time y end_time
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        // Ajustar si el horario cruza la medianoche
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        // Restar los minutos de descanso programados
        $totalMinutes = $start->diffInMinutes($end) - $this->total_break_minutes;

        // Retornar las horas programadas redondeadas a 2 decimales
        return round($totalMinutes / 60, 2);
    }

    // Calcular los minutos totales de descanso programados para este día
    public function getTotalBreakMinutesAttribute(): int
    {
        return $this->breaks->sum(function ($break) {
            return $break->duration_in_minutes;
        });
    }

    /**
     * Indica si el turno de este día termina al día siguiente (la salida es igual o anterior a la entrada),
     * por ejemplo 23:00 a 07:00.
     */
    public function crossesMidnight(): bool
    {
        if (blank($this->start_time) || blank($this->end_time)) {
            return false;
        }

        return Carbon::parse($this->end_time)->lessThanOrEqualTo(Carbon::parse($this->start_time));
    }
}
