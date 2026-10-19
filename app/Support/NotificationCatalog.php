<?php

namespace App\Support;

use App\Models\User;

/**
 * Catálogo único de los avisos que el sistema envía por la campanita o el correo. Cada
 * notificación declara su clave (`preferenceKey()`); aquí viven la etiqueta, la categoría, el
 * permiso que habilita recibirla y los valores por defecto. Agregar un aviso nuevo = una
 * entrada acá y la clave en su clase de notificación (un test exige que coincidan).
 *
 * Cada entrada: `label`, `description`, `category`, `permission` (null = cualquier usuario),
 * `mandatory` (no se puede apagar ni diferir), `digestable` (false = ya llega una vez al día por sí mismo, solo se envía al instante), `enabled`, `channels` y `frequency` por defecto.
 */
class NotificationCatalog
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_MAIL = 'mail';

    public const FREQUENCY_INSTANT = 'instant';

    public const FREQUENCY_DIGEST = 'digest';

    /** Hora del resumen diario por defecto (0–23, hora de `app.timezone`). */
    public const DEFAULT_DIGEST_HOUR = 8;

    /**
     * Categorías en el orden en que se muestran.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'terminal_health' => 'Salud de terminales',
            'terminal_linking' => 'Vinculación de terminales',
            'attendance' => 'Asistencia',
            'contracts_devices' => 'Contratos y dispositivos',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function channelOptions(): array
    {
        return [
            self::CHANNEL_DATABASE => 'Campanita',
            self::CHANNEL_MAIL => 'Correo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        return [
            self::FREQUENCY_INSTANT => 'Al instante',
            self::FREQUENCY_DIGEST => 'En el resumen diario',
        ];
    }

    /**
     * Todas las entradas por clave.
     *
     * @return array<string, array{label: string, description: string, category: string, permission: string|null, mandatory: bool, digestable: bool, enabled: bool, channels: array<int, string>, frequency: string}>
     */
    public static function all(): array
    {
        $entries = [
            'terminal.offline' => ['Terminal sin conexión', 'Un terminal dejó de reportar dentro de su horario de vigilancia (varios a la vez llegan en un solo aviso).', 'terminal_health', 'update_terminal'],
            'terminal.recovered' => ['Terminal reconectado', 'Un terminal que había avisado su desconexión volvió a reportar. Queda siempre en la bitácora del terminal.', 'terminal_health', 'update_terminal', ['enabled' => false]],
            'terminal.unlinked' => ['Terminal sin vincular', 'Un terminal perdió su acceso y no puede marcar hasta volver a vincularlo.', 'terminal_health', 'update_terminal', ['mandatory' => true]],
            'terminal.queue_stuck' => ['Cola de marcaciones atascada', 'Un terminal tiene marcaciones pendientes que no se envían hace más del tiempo configurado.', 'terminal_health', 'update_terminal'],
            'terminal.battery_low' => ['Batería baja', 'Un terminal está por debajo del porcentaje configurado y sin cargador.', 'terminal_health', 'update_terminal'],
            'terminal.no_enrolled' => ['Sucursal sin empleados enrolados', 'La sucursal de un terminal no tiene ningún empleado activo con rostro registrado.', 'terminal_health', 'update_terminal'],
            'terminal.cache_empty' => ['Terminal sin empleados en caché', 'Un terminal está en línea pero no tiene empleados en su caché local.', 'terminal_health', 'update_terminal'],
            'terminal.clock_skew' => ['Reloj del terminal desajustado', 'La hora de un terminal difiere del servidor más de lo permitido.', 'terminal_health', 'update_terminal'],
            'terminal.app_outdated' => ['App del terminal desactualizada', 'Un terminal sigue con una versión anterior de la app tras un despliegue.', 'terminal_health', 'update_terminal'],
            'terminal.not_installed' => ['Terminal en una pestaña del navegador', 'Un terminal lleva horas funcionando en una pestaña del navegador, sin instalar como app ni en modo kiosko.', 'terminal_health', 'update_terminal'],
            'terminal.tab_hidden' => ['Pestaña del terminal oculta', 'La pestaña de un terminal lleva horas oculta o minimizada: el navegador la frena y el equipo puede suspenderse.', 'terminal_health', 'update_terminal'],

            'terminal.pairing_requested' => ['Solicitud de vinculación', 'Un dispositivo pidió vincularse a un terminal con un código y espera aprobación.', 'terminal_linking', 'update_terminal'],
            'terminal.provisioned' => ['Terminal provisionado', 'Un dispositivo completó su vinculación. No llega a quien generó el enlace o abrió la ventana.', 'terminal_linking', 'update_terminal', ['channels' => ['database', 'mail']]],
            'terminal.link_window_expired' => ['Ventana de vinculación vencida', 'La ventana que abriste venció sin que ningún dispositivo la usara.', 'terminal_linking', 'update_terminal'],
            'terminal.command_expired' => ['Comando remoto vencido', 'Un comando que enviaste a un terminal venció sin aplicarse.', 'terminal_linking', 'update_terminal'],

            'attendance.open_shifts' => ['Jornadas sin salida', 'Resumen diario de jornadas con entrada y sin salida registrada.', 'attendance', 'view_any_attendance_day', ['digestable' => false]],
            'attendance.no_schedule' => ['Empleados sin horario', 'Resumen diario de empleados activos sin horario asignado.', 'attendance', 'update_schedule', ['digestable' => false]],
            'attendance.sync_conflict' => ['Conflicto de sincronización', 'Una marcación hecha sin conexión no pudo registrarse y necesita revisión.', 'attendance', 'update_attendance_mark_failure'],
            'attendance.face_enrollment' => ['Autoenrolamiento facial pendiente', 'Un empleado completó su registro facial y espera aprobación.', 'attendance', 'update_face_enrollment'],

            'contract.alert' => ['Contratos por vencer o vencidos', 'Contratos que se acercan a su fecha de fin o ya vencieron.', 'contracts_devices', 'view_any_contract', ['digestable' => false]],
            'device.mobile_linked' => ['Celular vinculado', 'Un empleado vinculó su celular para marcar asistencia.', 'contracts_devices', 'view_any_employee_device'],
            'device.mobile_relinked' => ['Celular re-vinculado', 'Un empleado vinculó un celular nuevo y se revocó el anterior.', 'contracts_devices', 'view_any_employee_device', ['mandatory' => true, 'channels' => ['database', 'mail']]],
            'device.link_throttled' => ['Límite de vinculación agotado', 'Se agotaron los intentos diarios de vinculación de celular desde una IP.', 'contracts_devices', 'view_any_employee_device'],
        ];

        $catalog = [];

        foreach ($entries as $key => $entry) {
            [$label, $description, $category, $permission] = $entry;
            $overrides = $entry[4] ?? [];

            $catalog[$key] = [
                'label' => $label,
                'description' => $description,
                'category' => $category,
                'permission' => $permission,
                'mandatory' => $overrides['mandatory'] ?? false,
                'digestable' => $overrides['digestable'] ?? true,
                'enabled' => $overrides['enabled'] ?? true,
                'channels' => $overrides['channels'] ?? [self::CHANNEL_DATABASE],
                'frequency' => self::FREQUENCY_INSTANT,
            ];
        }

        return $catalog;
    }

    /**
     * Entrada de una clave, o null si no existe.
     *
     * @return array{label: string, description: string, category: string, permission: string|null, mandatory: bool, digestable: bool, enabled: bool, channels: array<int, string>, frequency: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Entradas que el usuario puede configurar: las de los módulos a los que tiene acceso.
     * Super Admin las ve todas (`Gate::before`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forUser(User $user): array
    {
        return array_filter(
            self::all(),
            fn (array $entry) => $entry['permission'] === null || $user->can($entry['permission']),
        );
    }
}
