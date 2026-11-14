<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Configuración del envío de correo desde el panel. Apagado por defecto: mientras `mail_enabled` sea false rige
     * el `.env` como hasta ahora. La clave se guarda cifrada.
     */
    public function up(): void
    {
        $this->migrator->add('general.mail_enabled', false);
        $this->migrator->add('general.mail_host', '');
        $this->migrator->add('general.mail_port', 587);
        $this->migrator->add('general.mail_encryption', 'tls');
        $this->migrator->add('general.mail_username', '');
        $this->migrator->addEncrypted('general.mail_password', '');
        $this->migrator->add('general.mail_from_address', '');
        $this->migrator->add('general.mail_from_name', '');
    }

    public function down(): void
    {
        foreach (['enabled', 'host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'] as $name) {
            $this->migrator->delete("general.mail_{$name}");
        }
    }
};
