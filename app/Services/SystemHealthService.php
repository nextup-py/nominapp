<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\MailFailedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Estado del sistema para la página «Salud del sistema»: programador (cron), cola, correo, disco y logs. Cada chequeo
 * devuelve `status` (`ok`, `warning`, `danger`), un título corto y el detalle; nunca lanza (una falla al consultar es
 * en sí un estado `danger`). Agregar un chequeo = un método privado y una línea en `checks()`.
 */
class SystemHealthService
{
    /** Clave de caché donde el programador deja la hora de su última corrida. */
    public const SCHEDULER_KEY = 'system:scheduler_last_run';

    /** Minutos sin latido del programador a partir de los cuales se considera caído. */
    public const SCHEDULER_STALE_MINUTES = 5;

    /** Porcentaje de disco usado que dispara el aviso y el estado crítico. */
    public const DISK_WARNING_PERCENT = 85;

    public const DISK_DANGER_PERCENT = 95;

    /** Tamaño (MB) de un log a partir del cual se avisa. */
    public const LOG_WARNING_MB = 50;

    /**
     * Todos los chequeos, en el orden en que se muestran.
     *
     * @return array<string, array{status: string, title: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            'scheduler' => $this->guard(fn () => $this->scheduler()),
            'queue' => $this->guard(fn () => $this->queue()),
            'mail' => $this->guard(fn () => $this->mail()),
            'disk' => $this->guard(fn () => $this->disk()),
            'logs' => $this->guard(fn () => $this->logs()),
        ];
    }

    /** Peor estado entre todos los chequeos. */
    public function overall(): string
    {
        $statuses = array_column($this->checks(), 'status');

        return in_array('danger', $statuses, true) ? 'danger' : (in_array('warning', $statuses, true) ? 'warning' : 'ok');
    }

    /** Ejecuta un chequeo y convierte cualquier excepción en un estado crítico. */
    private function guard(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return ['status' => 'danger', 'title' => 'No se pudo comprobar', 'detail' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /** Programador: el comando `schedule:run` deja un latido cada minuto. */
    private function scheduler(): array
    {
        $last = Cache::get(self::SCHEDULER_KEY);

        if (! $last) {
            return ['status' => 'danger', 'title' => 'Sin latido del programador', 'detail' => 'Todavía no registró ninguna corrida. Verificá que el cron del servidor esté configurado.'];
        }

        $minutes = (int) $last->diffInMinutes(now());

        return $minutes > self::SCHEDULER_STALE_MINUTES
            ? ['status' => 'danger', 'title' => 'El programador no corre', 'detail' => "Última corrida hace {$minutes} minutos. Las tareas automáticas (asistencia, avisos) están detenidas."]
            : ['status' => 'ok', 'title' => 'Programador al día', 'detail' => 'Última corrida: '.$last->diffForHumans().'.'];
    }

    /** Cola: trabajos fallidos y pendientes. */
    private function queue(): array
    {
        $failed = DB::table('failed_jobs')->count();
        $pending = DB::table('jobs')->count();

        return $failed > 0
            ? ['status' => 'warning', 'title' => "{$failed} trabajo(s) fallido(s)", 'detail' => "Hay {$pending} pendiente(s). Revisá los logs para ver el motivo."]
            : ['status' => 'ok', 'title' => 'Cola sin fallos', 'detail' => "{$pending} trabajo(s) pendiente(s)."];
    }

    /** Correo: configurado, y si falló un envío que nadie leyó todavía. */
    private function mail(): array
    {
        if (! MailConfigurator::isEnabled()) {
            return ['status' => 'warning', 'title' => 'Correo no configurado', 'detail' => 'Los avisos llegan solo por la campanita. Se configura en Configuración General → Correo.'];
        }

        $unreadFailure = DatabaseNotification::query()
            ->where('type', MailFailedNotification::class)
            ->whereNull('read_at')
            ->exists();

        return $unreadFailure
            ? ['status' => 'danger', 'title' => 'Hubo fallas al enviar correos', 'detail' => 'Revisá la campanita y probá con «Enviar correo de prueba».']
            : ['status' => 'ok', 'title' => 'Correo configurado', 'detail' => 'Sin fallas de envío sin revisar.'];
    }

    /** Disco: espacio usado en la partición de la aplicación. */
    private function disk(): array
    {
        $total = (float) disk_total_space(storage_path());
        $free = (float) disk_free_space(storage_path());

        if ($total <= 0) {
            return ['status' => 'warning', 'title' => 'Espacio no disponible', 'detail' => 'El servidor no informa el espacio en disco.'];
        }

        $used = (int) round(($total - $free) / $total * 100);
        $detail = "{$used} % usado, ".round($free / 1024 ** 3, 1).' GB libres.';

        return match (true) {
            $used >= self::DISK_DANGER_PERCENT => ['status' => 'danger', 'title' => 'Disco casi lleno', 'detail' => $detail],
            $used >= self::DISK_WARNING_PERCENT => ['status' => 'warning', 'title' => 'Disco con poco espacio', 'detail' => $detail],
            default => ['status' => 'ok', 'title' => 'Espacio en disco', 'detail' => $detail],
        };
    }

    /** Logs: tamaño total y archivos demasiado grandes. */
    private function logs(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        $bytes = array_sum(array_map('filesize', $files));
        $big = array_filter($files, fn (string $f): bool => filesize($f) > self::LOG_WARNING_MB * 1024 * 1024);
        $detail = count($files).' archivo(s), '.round($bytes / 1024 ** 2, 1).' MB en total.';

        return $big !== []
            ? ['status' => 'warning', 'title' => 'Hay logs muy grandes', 'detail' => $detail.' El más pesado: '.basename(array_values($big)[0]).'. Se rotan solos con «logs:rotate».']
            : ['status' => 'ok', 'title' => 'Logs en tamaño normal', 'detail' => $detail];
    }

    /** Super Admin destinatarios de los avisos del sistema. */
    public static function recipients()
    {
        return User::role('Super Admin')->get();
    }
}
