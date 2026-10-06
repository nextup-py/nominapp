<?php

use App\Models\AttendanceMarkFailure;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/**
 * Comando de ejemplo para mostrar frases inspiradoras
 */
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Calcular asistencias del día
 * Se ejecuta todos los días a las 23:00 (hora Paraguay)
 * Calcula horas trabajadas, descansos, tardanzas, etc.
 */
Schedule::command('app:calculate-attendance')
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Cálculo automático de asistencias completado con éxito');
    })
    ->onFailure(function () {
        Log::error('Falló el cálculo automático de asistencias');
    });

/**
 * Verificar y generar registros de ausencias faltantes
 * Se ejecuta cada 15 minutos durante horario laboral (6am - 8pm)
 * Solo días laborables: Lunes a Sabado
 */
Schedule::command('attendance:check-missing')
    ->everyFifteenMinutes()
    ->between('06:00', '20:00')
    ->days([1, 2, 3, 4, 5, 6]) // 1 = Monday, 6 = Saturday
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Verificación de ausencias completada con éxito');
    })
    ->onFailure(function () {
        Log::error('Falló la verificación de ausencias');
    });

/**
 * Limpiar registros de fallos de marcación con más de 30 días
 * Se ejecuta diariamente a las 02:00 para evitar crecimiento indefinido de la tabla.
 *
 * Solo elimina los ya revisados (approved/dismissed) — un fallo 'pending' de más
 * de 30 días significa que nadie lo revisó todavía; borrarlo pierde para siempre
 * la evidencia y la posibilidad de reconstruir esa marcación.
 */
Schedule::call(function () {
    $deleted = AttendanceMarkFailure::where('occurred_at', '<', now()->subDays(30))
        ->whereIn('resolution_status', ['approved', 'dismissed'])
        ->delete();
    if ($deleted > 0) {
        Log::info("Limpieza de fallos de marcación: {$deleted} registros eliminados");
    }
})->dailyAt('02:00')->name('cleanup-mark-failures')->withoutOverlapping();

/**
 * Generar balances de vacaciones para todos los empleados activos
 * Se ejecuta el 1° de enero a las 00:05 para el año entrante
 */
Schedule::command('vacations:generate-annual-balances')
    ->yearlyOn(1, 1, '00:05')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Generación automática de balances de vacaciones completada');
    })
    ->onFailure(function () {
        Log::error('Falló la generación automática de balances de vacaciones');
    });

/**
 * Marcar como vencidos los contratos activos cuya end_date ya pasó
 * Se ejecuta a las 00:05 para que los estados estén actualizados al inicio del día
 */
Schedule::command('contracts:expire')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Expiración automática de contratos completada');
    })
    ->onFailure(function () {
        Log::error('Falló la expiración automática de contratos');
    });

/**
 * Notificar contratos próximos a vencer o ya vencidos
 * Se ejecuta diariamente a las 08:00 — una sola notificación por contrato (no duplica)
 */
Schedule::command('contracts:notify-expiring')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Notificación de contratos por vencer completada');
    })
    ->onFailure(function () {
        Log::error('Falló la notificación de contratos por vencer');
    });

/**
 * Expirar enrollments faciales vencidos
 * Se ejecuta cada hora para marcar como 'expired' los registros
 * en estado pending_capture cuyo expires_at ya pasó
 */
Schedule::command('face:expire-enrollments')
    ->hourly()
    ->withoutOverlapping()
    ->onSuccess(function () {
        Log::info('Expiración automática de enrollments faciales completada');
    })
    ->onFailure(function () {
        Log::error('Falló la expiración automática de enrollments faciales');
    });

/**
 * Purgar el historial de auditoría más antiguo que la retención configurada
 * Se ejecuta a las 03:00 (retención en Configuración General; 0 = conservar siempre)
 */
Schedule::command('audits:purge')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la purga automática del historial de auditoría');
    });

/**
 * Vencer solicitudes de vinculación de terminales sin resolver
 * Se ejecuta cada minuto: las solicitudes pendientes duran 10 minutos
 */
Schedule::command('terminals:expire-pairing')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló el vencimiento de solicitudes de vinculación de terminales');
    });
