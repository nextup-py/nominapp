<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lee los archivos `laravel*.log` recientes y agrupa los errores repetidos por «firma» (el mensaje sin números, ids ni
 * rutas), con contador, primera y última aparición y una explicación legible cuando el error es conocido. Lee solo el
 * final de cada archivo (`MAX_BYTES`) para no cargar logs enormes en memoria.
 */
class LogErrorAnalyzer
{
    /** Niveles que cuentan como error. */
    public const ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** Máximo de bytes que se leen del final de cada archivo. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Errores conocidos: patrón => [qué pasó, qué hacer].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hints(): array
    {
        return [
            '/Connection could not be established with host|Connection timed out.*smtp|SMTP/i' => ['No se pudo conectar con el servidor de correo.', 'Revisá Configuración General → Correo y probá con «Enviar correo de prueba».'],
            '/Too many connections|server has gone away|Connection refused.*3306|SQLSTATE\[HY000\] \[2002\]/i' => ['La base de datos no respondió.', 'Suele ser momentáneo; si se repite, avisá al proveedor del hosting.'],
            '/Allowed memory size/i' => ['Una operación se quedó sin memoria.', 'Anotá qué se estaba haciendo (reporte, exportación) y avisá a soporte.'],
            '/Maximum execution time/i' => ['Una operación tardó demasiado y se cortó.', 'Probá con un rango más chico; si se repite, avisá a soporte.'],
            '/Unable to locate file in Vite manifest|Vite manifest/i' => ['Faltan los archivos compilados de la interfaz.', 'Hay que subir de nuevo la carpeta public/build en el deploy.'],
            '/Permission denied|Failed to open stream|Unable to create a directory/i' => ['El servidor no puede escribir en una carpeta.', 'Revisar permisos de storage/ y bootstrap/cache/.'],
            '/No space left on device/i' => ['El disco del servidor está lleno.', 'Liberar espacio (logs viejos, copias) o ampliar el plan.'],
            '/Token mismatch|CSRF|Page Expired/i' => ['Una sesión venció mientras se enviaba un formulario.', 'No es una falla: el usuario solo tiene que recargar y reintentar.'],
            '/Unauthenticated|This action is unauthorized/i' => ['Alguien intentó entrar a algo sin permiso o sin sesión.', 'Normalmente no requiere acción.'],
            '/Integrity constraint violation|Duplicate entry/i' => ['Se intentó guardar un dato repetido o con referencias rotas.', 'Revisar el registro involucrado; anotá el mensaje completo.'],
            '/Class .* not found|Target class/i' => ['Falta un archivo de código en el servidor.', 'Suele ocurrir con un deploy incompleto: repetir el deploy.'],
        ];
    }

    /**
     * Explicación legible de un mensaje de error, o null si no se reconoce.
     *
     * @return array{what: string, todo: string}|null
     */
    public static function explain(string $message): ?array
    {
        foreach (self::hints() as $pattern => [$what, $todo]) {
            if (preg_match($pattern, $message) === 1) {
                return ['what' => $what, 'todo' => $todo];
            }
        }

        return null;
    }

    /**
     * Firma estable de un mensaje: sin números, uuids, rutas ni contenido entre comillas.
     */
    public static function signature(string $message): string
    {
        $first = trim(strtok($message, "\n") ?: $message);
        $first = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '#', $first);
        $first = preg_replace('/(["\'])(?:(?!\1).)*\1/', '$1…$1', $first);
        $first = preg_replace('#(?:/[\w.\-]+){2,}#', '/ruta', $first);
        $first = preg_replace('/\d+/', '#', $first);

        return mb_substr($first, 0, 200);
    }

    /**
     * Entradas de error de los últimos `$days` días, agrupadas por firma y ordenadas de mayor a menor cantidad.
     *
     * @return Collection<int, array{signature: string, message: string, level: string, count: int, first_seen: Carbon, last_seen: Carbon, explanation: ?array{what: string, todo: string}}>
     */
    public function groups(int $days = 7, ?string $directory = null): Collection
    {
        $since = now()->subDays($days)->startOfDay();
        $groups = [];

        foreach ($this->entries($since, $directory ?? storage_path('logs')) as $entry) {
            $key = self::signature($entry['message']);
            $seen = $entry['date'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'signature' => $key,
                    'message' => trim(strtok($entry['message'], "\n") ?: $entry['message']),
                    'level' => $entry['level'],
                    'count' => 0,
                    'first_seen' => $seen,
                    'last_seen' => $seen,
                    'explanation' => self::explain($entry['message']),
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['first_seen'] = $seen->lt($groups[$key]['first_seen']) ? $seen : $groups[$key]['first_seen'];
            $groups[$key]['last_seen'] = $seen->gt($groups[$key]['last_seen']) ? $seen : $groups[$key]['last_seen'];
        }

        return collect($groups)->sortByDesc('count')->values();
    }

    /**
     * Entradas de nivel error desde `$since`, leyendo solo el final de cada archivo.
     *
     * @return \Generator<int, array{date: Carbon, level: string, message: string}>
     */
    public function entries(Carbon $since, string $directory): \Generator
    {
        foreach (glob(rtrim($directory, '/').'/laravel*.log') ?: [] as $file) {
            if (filemtime($file) < $since->timestamp) {
                continue;
            }

            $content = $this->tail($file);

            if (! preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [\w.\-]+\.([A-Z]+): (.*?)(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] |\z)/ms', $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as [, $date, $level, $message]) {
                if (! in_array($level, self::ERROR_LEVELS, true)) {
                    continue;
                }

                $when = Carbon::parse($date);

                if ($when->gte($since)) {
                    yield ['date' => $when, 'level' => $level, 'message' => trim($message)];
                }
            }
        }
    }

    /** Final del archivo (hasta `MAX_BYTES`), sin la primera línea si quedó cortada. */
    private function tail(string $file): string
    {
        $size = filesize($file) ?: 0;
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > self::MAX_BYTES) {
            fseek($handle, -self::MAX_BYTES, SEEK_END);
            fgets($handle);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }
}
