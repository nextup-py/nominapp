<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Aplica al envío de correo los datos cargados en Configuración General → Correo.
 *
 * Mientras `mail_enabled` sea false (el estado de fábrica) rige el `.env`, como siempre. Con el correo activo, el
 * servidor SMTP del panel reemplaza al del `.env` la primera vez que se usa el envío en cada petición (ver
 * `AppServiceProvider`): no se lee la base en las peticiones que no mandan correo. Nunca debe tirar abajo la
 * aplicación: si los ajustes no existen todavía (instalación sin migrar) o fallan, se ignora y rige el `.env`.
 */
class MailConfigurator
{
    /** Cifrados admitidos: `tls` (STARTTLS) y `ssl` (cifrado desde el inicio de la conexión). */
    public const ENCRYPTIONS = ['tls' => 'TLS (STARTTLS, puerto 587)', 'ssl' => 'SSL (puerto 465)'];

    /**
     * El correo del panel está activo y completo (servidor y remitente cargados).
     *
     * @param  GeneralSettings|null  $settings  Ajustes ya cargados; null los lee de la base.
     */
    public static function isEnabled(?GeneralSettings $settings = null): bool
    {
        try {
            $settings ??= app(GeneralSettings::class);

            return $settings->mail_enabled && filled($settings->mail_host) && filled($settings->mail_from_address);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Aplica los ajustes guardados a la configuración de correo si el correo del panel está activo.
     * Se llama al resolver el gestor de correo; cualquier fallo se registra y se ignora.
     */
    public static function applyRuntime(): void
    {
        try {
            $settings = app(GeneralSettings::class);

            if (! self::isEnabled($settings)) {
                return;
            }

            self::apply(self::valuesFrom($settings));
        } catch (Throwable $e) {
            Log::warning('No se pudo aplicar la configuración de correo del panel: '.$e->getMessage());
        }
    }

    /**
     * Valores del formulario o de los ajustes, normalizados.
     *
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string}
     */
    public static function valuesFrom(GeneralSettings $settings): array
    {
        return [
            'host' => (string) $settings->mail_host,
            'port' => (int) $settings->mail_port,
            'encryption' => (string) $settings->mail_encryption,
            'username' => (string) $settings->mail_username,
            'password' => (string) $settings->mail_password,
            'from_address' => (string) $settings->mail_from_address,
            'from_name' => (string) $settings->mail_from_name,
        ];
    }

    /**
     * Pone el SMTP indicado como envío por defecto de esta petición.
     *
     * @param  array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string}  $values
     */
    public static function apply(array $values): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => ($values['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $values['host'],
            'mail.mailers.smtp.port' => (int) $values['port'],
            'mail.mailers.smtp.username' => filled($values['username'] ?? null) ? $values['username'] : null,
            'mail.mailers.smtp.password' => filled($values['password'] ?? null) ? $values['password'] : null,
            'mail.from.address' => $values['from_address'],
            'mail.from.name' => filled($values['from_name'] ?? null) ? $values['from_name'] : config('app.name'),
        ]);

        // Si el envío ya se había resuelto con otros datos (por ejemplo, una prueba desde el formulario), se rearma.
        Mail::forgetMailers();
    }

    /**
     * Manda un correo de prueba con los datos indicados (los del formulario, guardados o no).
     *
     * @param  array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string}  $values
     *
     * @throws Throwable Si el servidor rechaza la conexión, las credenciales o el mensaje.
     */
    public static function sendTest(array $values, string $to): void
    {
        self::apply($values);

        Mail::raw(
            'Este es un correo de prueba de '.config('app.name').".\n\nSi lo recibiste, el envío de correo está bien configurado.",
            fn ($message) => $message->to($to)->subject('Prueba de correo — '.config('app.name')),
        );
    }
}
