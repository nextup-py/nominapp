<?php

namespace App\Support;

use App\Models\User;
use App\Services\MailConfigurator;

/**
 * Catálogo único de los avisos que el sistema envía por la campanita o el correo. Cada
 * notificación declara su clave (`preferenceKey()`); aquí viven la etiqueta, la categoría, el
 * permiso que habilita recibirla y los valores por defecto. Agregar un aviso nuevo = una
 * entrada acá y la clave en su clase de notificación (un test exige que coincidan).
 *
 * Cada entrada: `label`, `description`, `category`, `permission` (null = cualquier usuario; una lista = alcanza con tener uno de esos permisos),
 * `mandatory` (no se puede apagar ni diferir), `mail_when_configured` (suma el correo a los canales por defecto cuando el correo del panel está activo), `digestable` (false = ya llega una vez al día por sí mismo, solo se envía al instante), `enabled`, `channels` y `frequency` por defecto.
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
            'approvals' => 'Aprobaciones',
            'vacations' => 'Vacaciones',
            'system' => 'Sistema',
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
            'terminal.offline' => ['Terminal sin conexión', 'Un terminal dejó de reportar dentro de su horario de vigilancia (varios a la vez llegan en un solo aviso).', 'terminal_health', 'update_terminal', ['mail_when_configured' => true]],
            'terminal.link_lost' => ['Terminal perdió el vínculo', 'Un terminal que seguía vinculado en el servidor pidió vincularse de nuevo: el navegador del dispositivo perdió el acceso guardado (datos borrados, ventana privada, otro perfil). Queda siempre en la bitácora del terminal.', 'terminal_health', 'update_terminal', ['mail_when_configured' => true]],
            'terminal.recovered' => ['Terminal reconectado', 'Un terminal que había avisado su desconexión volvió a reportar. Queda siempre en la bitácora del terminal.', 'terminal_health', 'update_terminal', ['enabled' => false]],
            'terminal.unlinked' => ['Terminal sin vincular', 'Un terminal perdió su acceso y no puede marcar hasta volver a vincularlo.', 'terminal_health', 'update_terminal', ['mandatory' => true, 'mail_when_configured' => true]],
            'terminal.queue_stuck' => ['Cola de marcaciones atascada', 'Un terminal tiene marcaciones pendientes que no se envían hace más del tiempo configurado.', 'terminal_health', 'update_terminal'],
            'terminal.battery_low' => ['Batería baja', 'Un terminal está por debajo del porcentaje configurado y sin cargador.', 'terminal_health', 'update_terminal'],
            'terminal.no_charger' => ['Terminal sin cargador', 'Un terminal en línea lleva más del tiempo configurado en Configuración General sin cargador, antes de que la batería esté baja. Solo si el navegador informa la batería.', 'terminal_health', 'update_terminal'],
            'terminal.outside_branch' => ['Terminal fuera de la sucursal', 'Un terminal marcado como equipo móvil mandó dos lecturas seguidas de ubicación fuera del radio de su sucursal. Solo con el equipo en línea y dentro de su horario.', 'terminal_health', 'update_terminal'],
            'terminal.no_enrolled' => ['Sucursal sin empleados enrolados', 'La sucursal de un terminal no tiene ningún empleado activo con rostro registrado.', 'terminal_health', 'update_terminal'],
            'terminal.cache_empty' => ['Terminal sin empleados en caché', 'Un terminal está en línea pero no tiene empleados en su caché local.', 'terminal_health', 'update_terminal'],
            'terminal.clock_skew' => ['Reloj del terminal desajustado', 'La hora de un terminal difiere del servidor más de lo permitido.', 'terminal_health', 'update_terminal'],
            'terminal.app_outdated' => ['App del terminal desactualizada', 'Un terminal sigue con una versión anterior de la app tras un despliegue.', 'terminal_health', 'update_terminal'],
            'terminal.not_installed' => ['Terminal en una pestaña del navegador', 'Un terminal lleva horas funcionando en una pestaña del navegador, sin instalar como app ni en modo kiosko.', 'terminal_health', 'update_terminal'],
            'terminal.low_availability' => ['Terminales con poca disponibilidad', 'Resumen semanal de los terminales que estuvieron en línea menos del mínimo configurado en Configuración General.', 'terminal_health', 'update_terminal', ['digestable' => false]],
            'terminal.tab_hidden' => ['Pestaña del terminal oculta', 'La pestaña de un terminal lleva horas oculta o minimizada: el navegador la frena y el equipo puede suspenderse.', 'terminal_health', 'update_terminal'],

            'terminal.pairing_requested' => ['Solicitud de vinculación', 'Un dispositivo pidió vincularse a un terminal con un código y espera aprobación.', 'terminal_linking', 'update_terminal'],
            'terminal.device_replaced' => ['Dispositivo reemplazado en un terminal', 'Un dispositivo nuevo se vinculó a un terminal cuyo dispositivo anterior seguía en línea: el anterior quedó sin acceso. Suele indicar dos equipos abiertos en la misma URL. Queda siempre en la bitácora del terminal.', 'terminal_linking', 'update_terminal'],
            'terminal.multiple_devices' => ['Dos equipos en el mismo terminal', 'Dos equipos distintos pidieron vincularse al mismo terminal en 24 horas: se van a quitar el acceso uno al otro. Una vez por terminal cada 24 horas.', 'terminal_linking', 'update_terminal'],
            'terminal.provisioned' => ['Terminal provisionado', 'Un dispositivo completó su vinculación. No llega a quien generó el enlace o abrió la ventana.', 'terminal_linking', 'update_terminal', ['channels' => ['database', 'mail'], 'frequency' => self::FREQUENCY_DIGEST]],
            'terminal.link_window_expired' => ['Ventana de vinculación vencida', 'La ventana que abriste venció sin que ningún dispositivo la usara.', 'terminal_linking', 'update_terminal'],
            'terminal.command_expired' => ['Comando remoto vencido', 'Un comando que enviaste a un terminal venció sin aplicarse.', 'terminal_linking', 'update_terminal'],

            'attendance.open_shifts' => ['Jornadas sin salida', 'Resumen diario de jornadas con entrada y sin salida registrada.', 'attendance', 'view_any_attendance_day', ['digestable' => false]],
            'attendance.auto_closed' => ['Jornadas cerradas automáticamente', 'Resumen del cierre automático de jornadas sin salida: cuántas se cerraron solas y cuántas quedan para revisar. Solo se envía si el cierre automático está activado.', 'attendance', 'view_any_attendance_day', ['digestable' => false]],
            'attendance.no_exit_today' => ['Quién se fue sin marcar la salida hoy', 'Aviso de la noche con los empleados cuyo turno terminó hace más de 1 hora y siguen sin salida, para consultarles mientras se acuerdan.', 'attendance', 'view_any_attendance_day', ['digestable' => false]],
            'attendance.no_schedule' => ['Empleados sin horario', 'Resumen diario de empleados activos sin horario asignado.', 'attendance', 'update_schedule', ['digestable' => false]],
            'attendance.sync_conflict' => ['Conflicto de sincronización', 'Una marcación hecha sin conexión no pudo registrarse y necesita revisión.', 'attendance', 'update_attendance_mark_failure'],
            'attendance.face_enrollment' => ['Autoenrolamiento facial pendiente', 'Un empleado completó su registro facial y espera aprobación.', 'attendance', 'update_face_enrollment'],

            'contract.alert' => ['Contratos por vencer o vencidos', 'Contratos que se acercan a su fecha de fin o ya vencieron.', 'contracts_devices', 'view_any_contract', ['digestable' => false, 'mail_when_configured' => true]],
            'device.mobile_linked' => ['Celular vinculado', 'Un empleado vinculó su celular para marcar asistencia.', 'contracts_devices', 'view_any_employee_device', ['frequency' => self::FREQUENCY_DIGEST]],
            'device.mobile_relinked' => ['Celular re-vinculado', 'Un empleado vinculó un celular nuevo y se revocó el anterior.', 'contracts_devices', 'view_any_employee_device', ['mandatory' => true, 'channels' => ['database', 'mail']]],
            'device.mobile_link_blocked' => ['Vinculación de celular sin permiso', 'Un empleado no habilitado para marcar desde el celular intentó vincularlo.', 'contracts_devices', 'update_employee'],
            'approvals.pending' => ['Solicitudes pendientes de aprobar', 'Resumen diario de adelantos, préstamos, retiros de mercadería y permisos que esperan una decisión de quien los aprueba. Solo le llega a quien tiene permiso de aprobar alguno de ellos.', 'approvals', ['approve_advance', 'approve_loan', 'approve_merchandise_withdrawal', 'approve_employee_leave'], ['digestable' => false]],
            'vacation.pending' => ['Vacaciones pendientes de decidir', 'Solicitudes de vacaciones que llevan varios días sin aprobar ni rechazar (los días se configuran en Configuración de nómina). No se repite mientras el anterior siga sin leer.', 'vacations', 'update_vacation', ['digestable' => false]],
            'vacation.unpaid' => ['Vacaciones aprobadas sin marcar como pagadas', 'Vacaciones aprobadas cuyo inicio fue hace más de 7 días y no figuran pagadas. No se repite mientras el anterior siga sin leer.', 'vacations', 'update_vacation', ['digestable' => false]],
            'vacation.unused' => ['Derecho anual de vacaciones sin tomar', 'Cada lunes, los empleados que tienen sin usar todo el derecho de un año, para programar sus vacaciones.', 'vacations', 'update_vacation', ['digestable' => false]],
            'vacation.anniversary' => ['Empleados que cumplen su primer año', 'Quienes cumplen el primer año de antigüedad en los próximos días y empiezan a tener derecho a vacaciones. No se repite mientras el anterior siga sin leer.', 'vacations', 'update_vacation', ['digestable' => false]],
            'system.mail_failed' => ['Fallo del envío de correo', 'El servidor de correo no pudo enviar un mensaje: los avisos por correo no están llegando (clave vencida, servidor caído). Solo para el Super Admin; no se repite mientras el anterior siga sin leer.', 'system', 'super-admin', ['mandatory' => true, 'digestable' => false]],
            'holidays.missing' => ['Faltan los feriados del año', 'El año en curso (o el siguiente desde noviembre) no tiene los feriados cargados: afecta vacaciones, asistencia y horas extra. No se repite mientras el anterior siga sin leer.', 'attendance', 'create_holiday', ['digestable' => false]],
            'system.new_error' => ['Errores nuevos en el sistema', 'Apareció un error que no se había visto antes en los logs. Solo para el Super Admin; no se repite por el mismo error.', 'system', 'super-admin', ['digestable' => false]],
            'system.new_login_location' => ['Ingreso desde un lugar nuevo', 'Tu cuenta ingresó desde una IP que no habías usado antes. Es un aviso de seguridad solo para vos.', 'system', null, ['mandatory' => true, 'digestable' => false]],
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
                'mail_when_configured' => $overrides['mail_when_configured'] ?? false,
                'frequency' => $overrides['frequency'] ?? self::FREQUENCY_INSTANT,
            ];
        }

        return $catalog;
    }

    /**
     * El usuario tiene el permiso que habilita un aviso: `null` = cualquiera; una lista = alcanza con tener uno.
     *
     * @param  string|array<int, string>|null  $permission
     */
    public static function userCan(User $user, string|array|null $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        foreach ((array) $permission as $name) {
            if ($user->can($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canales por defecto de una entrada: los del catálogo y, si la entrada lo pide (`mail_when_configured`) y el
     * correo del panel está activo, también el correo. Sin el correo configurado nada cambia (la campanita de siempre).
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    public static function effectiveChannels(array $entry): array
    {
        $channels = $entry['channels'];

        if (($entry['mail_when_configured'] ?? false) && MailConfigurator::isEnabled() && ! in_array(self::CHANNEL_MAIL, $channels, true)) {
            $channels[] = self::CHANNEL_MAIL;
        }

        return $channels;
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
            fn (array $entry) => self::userCan($user, $entry['permission']),
        );
    }
}
