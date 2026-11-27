<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Models\Terminal;
use App\Services\LocationRadiusService;
use App\Services\MailConfigurator;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ManageGeneralSettings extends SettingsPage
{
    use UsesNavigationLayout;

    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuración General';

    protected static ?string $title = 'Configuración General';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static string $settings = GeneralSettings::class;

    /**
     * Botón para volver a abrir el asistente de configuración inicial —
     * solo Super Admin, y no bloqueante (setup_completed ya está en true
     * en este punto, así que EnsureSetupIsComplete nunca redirige durante
     * la reapertura).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reopenSetupWizard')
                ->label('Reabrir asistente de configuración inicial')
                ->icon('heroicon-o-rocket-launch')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false)
                ->requiresConfirmation()
                ->modalDescription('Podrás revisar y ajustar empresa, módulos y parámetros de nómina. Esto no bloqueará el acceso al resto del panel.')
                ->modalSubmitActionLabel('Sí, abrir asistente')
                ->url(fn () => SetupWizardPage::getUrl()),

            Action::make('sendTestMail')
                ->label('Enviar correo de prueba')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false)
                ->modalHeading('Enviar correo de prueba')
                ->modalDescription('Se envía con los datos que ves en la sección Correo, estén guardados o no, así se puede probar antes de guardar. La clave guardada se usa si el campo está vacío.')
                ->modalSubmitActionLabel('Enviar')
                ->form([
                    TextInput::make('to')
                        ->label('Enviar a')
                        ->email()
                        ->required()
                        ->default(fn () => Auth::user()?->email),
                ])
                ->action(fn (array $data) => $this->sendTestMail($data['to'])),
        ];
    }

    /**
     * Envía un correo de prueba con los datos del formulario (la clave guardada si el campo está vacío) y muestra el
     * resultado: llegó al servidor o el motivo del error. No guarda nada.
     */
    protected function sendTestMail(string $to): void
    {
        $state = $this->form->getRawState();
        $settings = app(GeneralSettings::class);

        $values = [
            'host' => trim((string) ($state['mail_host'] ?? '')),
            'port' => (int) ($state['mail_port'] ?? 587),
            'encryption' => (string) ($state['mail_encryption'] ?? 'tls'),
            'username' => trim((string) ($state['mail_username'] ?? '')),
            'password' => filled($state['mail_password'] ?? null) ? (string) $state['mail_password'] : (string) $settings->mail_password,
            'from_address' => trim((string) ($state['mail_from_address'] ?? '')),
            'from_name' => trim((string) ($state['mail_from_name'] ?? '')),
        ];

        if ($values['host'] === '' || $values['from_address'] === '') {
            Notification::make()->warning()->title('Faltan datos')->body('Completá el servidor y el correo remitente antes de probar.')->send();

            return;
        }

        try {
            MailConfigurator::sendTest($values, $to);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo enviar el correo de prueba')
                ->body(str($e->getMessage())->limit(300)->toString())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Correo de prueba enviado')
            ->body("El servidor aceptó el mensaje para {$to}. Revisá la bandeja de entrada (y el spam).")
            ->send();
    }

    /**
     * Los campos de texto vacíos llegan como null y las propiedades de correo son string: sin esto, guardar la página
     * con el correo sin configurar lanza un TypeError.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['mail_host', 'mail_username', 'mail_from_address', 'mail_from_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = trim((string) $data[$field]);
            }
        }

        return $data;
    }

    /**
     * La clave de correo nunca se manda al navegador: el campo se muestra vacío y, si se deja así al guardar, se conserva.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['mail_password'] = null;

        return $data;
    }

    /**
     * Define el formulario de configuración general del sistema.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configuración Laboral')
                    ->description('Parámetros de trabajo y horarios')
                    ->icon('heroicon-o-clock')
                    ->columns(2)
                    ->schema([
                        Select::make('timezone')
                            ->label('Zona horaria')
                            ->options([
                                'America/Asuncion' => 'América/Asunción (UTC -3)',
                                'America/Argentina/Buenos_Aires' => 'América/Buenos Aires (UTC -3)',
                                'America/Sao_Paulo' => 'América/São Paulo (UTC -3)',
                                'America/Montevideo' => 'América/Montevideo (UTC -3)',
                                'America/Santiago' => 'América/Santiago (UTC -4)',
                            ])
                            ->native(false)
                            ->default('America/Asuncion')
                            ->searchable()
                            ->helperText('Zona horaria para el cálculo de fechas y horas'),

                        TextInput::make('absence_threshold_minutes')
                            ->label('Tolerancia para ausencia')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(240)
                            ->default(30)
                            ->suffix('minutos')
                            ->helperText('Minutos de gracia tras la hora de entrada antes de marcar al empleado como ausente'),

                        TextInput::make('attendance_min_reentry_minutes')
                            ->label('Mínimo entre salida y nueva entrada')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(240)
                            ->default(5)
                            ->suffix('minutos')
                            ->helperText('Tiempo que debe pasar después de marcar la salida para poder marcar una nueva entrada el mismo día (segundo turno). Evita reentradas por error. 0 = sin mínimo.'),

                        TextInput::make('attendance_min_exit_minutes')
                            ->label('Mínimo entre entrada y salida')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(2)
                            ->suffix('minutos')
                            ->helperText('Tiempo que debe pasar después de marcar la entrada para poder marcar la salida. Evita salidas por un toque equivocado. 0 = sin mínimo.'),

                        TextInput::make('attendance_overnight_max_hours')
                            ->label('Límite de jornada nocturna abierta')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(48)
                            ->default(16)
                            ->suffix('horas')
                            ->helperText('Si la última marcación de la jornada de ayer es más antigua que esto, ya no se considera un turno nocturno en curso: una salida nueva no se le asigna y queda como conflicto. 0 = sin límite.'),

                        TextInput::make('attendance_late_tolerance_minutes')
                            ->label('Tolerancia de tardanza')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(5)
                            ->suffix('minutos')
                            ->helperText('Hasta este margen de atraso en la entrada no se considera tardanza. Pasado el margen cuentan todos los minutos de atraso (con 5, llegar 7 minutos tarde son 7 minutos de tardanza). 0 = sin tolerancia. Aplica a las jornadas que se calculen desde ahora; las ya calculadas no cambian salvo que se recalculen.'),

                        Toggle::make('attendance_auto_close_enabled')
                            ->label('Cerrar solo las jornadas sin salida')
                            ->live()
                            ->helperText('Cada día, a la hora indicada, el sistema cierra las jornadas abiertas de los últimos 7 días: con la salida del horario o, si quedó un descanso o una marcación cerca de la hora de salida, con esa marcación real. Respeta nóminas generadas y decisiones de RR.HH., y deja un resumen en la campanita. Apagado = RR.HH. las cierra a mano.'),

                        TimePicker::make('attendance_auto_close_time')
                            ->label('Hora del cierre automático')
                            ->seconds(false)
                            ->native(false)
                            ->format('H:i')
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('attendance_auto_close_enabled'))
                            ->helperText('Conviene antes de que empiece la jornada y después de que terminen los turnos nocturnos.'),

                        Toggle::make('attendance_no_exit_notice_enabled')
                            ->label('Avisar cada noche quién se fue sin marcar la salida')
                            ->live()
                            ->helperText('Una notificación a quienes ven asistencias con la lista de los empleados cuyo turno terminó hace más de 1 hora y siguen sin salida, para consultarles mientras se acuerdan. No se repite mientras el aviso anterior siga sin leer.'),

                        TimePicker::make('attendance_no_exit_notice_time')
                            ->label('Hora del aviso')
                            ->seconds(false)
                            ->native(false)
                            ->format('H:i')
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('attendance_no_exit_notice_enabled')),

                        Toggle::make('attendance_mobile_marking_default')
                            ->label('Permitir marcar desde el celular por defecto')
                            ->helperText('Valor para los empleados que no lo tienen definido ni en su ficha, ni en su sucursal ni en su empresa. Apagado = solo desde el terminal. Los celulares ya vinculados de quienes dejen de estar habilitados se desvinculan solos en su próxima conexión.'),

                        TextInput::make('attendance_location_default_radius')
                            ->label('Radio de marcación desde el celular')
                            ->numeric()
                            ->minValue(LocationRadiusService::MIN_RADIUS)
                            ->maxValue(LocationRadiusService::MAX_RADIUS)
                            ->default(150)
                            ->required()
                            ->suffix('metros')
                            ->helperText('Distancia máxima al punto de trabajo (del empleado o de su sucursal con control de ubicación) para marcar desde el celular. Fuera de ella la marcación se acepta pero queda señalada para revisión. Cada empleado o sucursal puede tener el suyo.'),
                    ]),

                Section::make('Configuración de Contratos')
                    ->description('Parámetros para alertas de vencimiento de contratos')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        TextInput::make('contract_alert_days')
                            ->label('Días de anticipación para alertas')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(180)
                            ->default(30)
                            ->suffix('días')
                            ->helperText('Cantidad de días antes del vencimiento para mostrar alertas de contratos'),

                        TextInput::make('contract_trial_alert_days')
                            ->label('Aviso de fin de período de prueba')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(7)
                            ->suffix('días antes')
                            ->helperText('Un resumen diario avisa de los contratos cuya prueba termina en estos días. 0 apaga el aviso.'),

                        TextInput::make('document_alert_days')
                            ->label('Aviso de documentos por vencer')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(365)
                            ->default(30)
                            ->suffix('días antes')
                            ->helperText('Un resumen avisa de los documentos de los legajos que vencen en estos días y de los que ya vencieron. 0 apaga el aviso.'),

                        Toggle::make('contract_force_indefinite_on_second_renewal')
                            ->label('Pasar a indefinido en la segunda renovación (Art. 53 CLT)')
                            ->default(true)
                            ->helperText('Al renovar por segunda vez un contrato a plazo fijo, el nuevo contrato se crea indefinido. Apagado: no cambia solo. Aunque esté encendido, cada renovación puede mantener el plazo fijo indicando el motivo. Valide con su contador o abogado antes de apagarlo.'),
                    ]),

                Section::make('Reconocimiento Facial')
                    ->description('Configuración para el registro y marcación facial de empleados')
                    ->icon('heroicon-o-finger-print')
                    ->columns(3)
                    ->schema([
                        TextInput::make('face_enrollment_expiry_hours')
                            ->label('Validez del enlace de registro')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(720)
                            ->default(48)
                            ->suffix('horas')
                            ->helperText('Tiempo de validez del enlace de registro facial enviado al empleado'),

                        TextInput::make('face_threshold')
                            ->label('Umbral de reconocimiento')
                            ->numeric()
                            ->minValue(0.1)
                            ->maxValue(1.0)
                            ->step(0.01)
                            ->default(0.45)
                            ->helperText('Distancia máxima para aceptar un match facial. Menor = más estricto (rango recomendado: 0.35 – 0.60)'),

                        TextInput::make('face_min_confidence_gap')
                            ->label('Gap mínimo de confianza')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(1.0)
                            ->step(0.01)
                            ->default(0.1)
                            ->helperText('Diferencia mínima entre el mejor y el segundo candidato para evitar confusiones entre rostros similares'),
                    ]),

                Section::make('Terminales de Marcación')
                    ->description('Configuración de conectividad para terminales offline (marcación vía PWA)')
                    ->icon('heroicon-o-wifi')
                    ->schema([
                        TextInput::make('terminal_stale_threshold_minutes')
                            ->label('Umbral de desconexión')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(Terminal::MIN_STALE_MINUTES)
                            ->maxValue(4320)
                            ->default(120)
                            ->suffix('minutos')
                            ->helperText('Minutos sin latido exitoso antes de marcar un terminal como desconectado en el panel (mínimo '.Terminal::MIN_STALE_MINUTES.'). Un terminal sano reporta cada ~90 segundos; cada terminal puede definir su propio umbral.'),

                        TextInput::make('terminal_queue_stuck_minutes')
                            ->label('Cola de marcaciones atascada')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(5)
                            ->maxValue(1440)
                            ->default(15)
                            ->suffix('minutos')
                            ->helperText('Minutos con marcaciones pendientes o en conflicto sin vaciarse antes de avisar por la campanita'),

                        TextInput::make('terminal_low_battery_percent')
                            ->label('Batería baja')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(5)
                            ->maxValue(50)
                            ->default(20)
                            ->suffix('%')
                            ->helperText('Se avisa cuando un terminal sin cargador baja de este nivel (solo si el navegador informa la batería)'),

                        TextInput::make('terminal_no_charger_minutes')
                            ->label('Terminal sin cargador')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->default(30)
                            ->suffix('minutos')
                            ->helperText('Tiempo sin cargador a partir del cual el terminal le recomienda a quien lo usa que lo enchufe y se avisa a quien gestiona terminales, antes de que la batería esté baja. 0 = apagado. Solo si el navegador informa la batería'),

                        TextInput::make('terminal_clock_skew_max_seconds')
                            ->label('Desfase de reloj tolerado')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(30)
                            ->maxValue(3600)
                            ->default(120)
                            ->suffix('segundos')
                            ->helperText('Se avisa cuando el reloj de un terminal difiere del servidor por más de este valor en varios reportes seguidos. Las marcaciones ya se corrigen con ese desfase; avisar sirve para que alguien revise la fecha y hora del dispositivo'),

                        TextInput::make('terminal_camera_resume_minutes')
                            ->label('Reencendido de la cámara apagada')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(240)
                            ->default(2)
                            ->suffix('minutos')
                            ->helperText('Si alguien apaga la cámara a mano desde el menú del terminal, se vuelve a encender sola pasado este tiempo, para no dejar el terminal inutilizado por olvido. 0 = queda apagada hasta que se encienda a mano'),

                        Toggle::make('terminal_confirm_exit')
                            ->label('Confirmar la salida y los descansos en el terminal')
                            ->default(true)
                            ->helperText('Al reconocer a un empleado, la salida y los descansos piden un toque en «Marcar» en vez de registrarse solos. Evita que una salida se marque por pasar frente a la cámara. La entrada sigue siendo automática'),

                        TextInput::make('terminal_selection_timeout_seconds')
                            ->label('Espera de la pantalla de selección')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(120)
                            ->default(15)
                            ->suffix('segundos')
                            ->helperText('Si nadie toca un botón, el terminal vuelve a la cámara sin registrar nada. Aplica a la confirmación y a la selección del tipo de marcación. 0 = sin límite de espera'),

                        TextInput::make('terminal_events_retention_days')
                            ->label('Retención de la bitácora de terminales')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(90)
                            ->suffix('días')
                            ->helperText('Los eventos más antiguos se eliminan automáticamente. 0 = conservar siempre'),

                        TextInput::make('terminal_stats_retention_days')
                            ->label('Retención del historial de confiabilidad')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->default(90)
                            ->suffix('días')
                            ->helperText('Resumen diario de disponibilidad, cortes e identificaciones de cada terminal. 0 = conservar siempre'),

                        TextInput::make('terminal_min_availability_percent')
                            ->label('Disponibilidad mínima semanal')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(90)
                            ->suffix('%')
                            ->helperText('Cada lunes se avisa de los terminales que estuvieron en línea menos que esto durante la semana anterior. 0 = no avisar'),
                    ]),

                Section::make('Correo')
                    ->description('Servidor desde el que el sistema envía los avisos por correo. Apagado, el envío depende de la configuración del servidor (.env).')
                    ->icon('heroicon-o-envelope')
                    ->schema([
                        Toggle::make('mail_enabled')
                            ->label('Enviar correos con esta configuración')
                            ->default(false)
                            ->live()
                            ->helperText('Antes de activarlo, usá «Enviar correo de prueba» (arriba). Si el servidor falla, los avisos siguen llegando a la campanita.'),

                        TextInput::make('mail_host')
                            ->label('Servidor SMTP')
                            ->placeholder('Ej: mail.miempresa.com.py')
                            ->maxLength(190)
                            ->required(fn (Get $get): bool => (bool) $get('mail_enabled')),

                        TextInput::make('mail_port')
                            ->label('Puerto')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(587)
                            ->required(),

                        Select::make('mail_encryption')
                            ->label('Cifrado')
                            ->options(MailConfigurator::ENCRYPTIONS)
                            ->default('tls')
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->required(),

                        TextInput::make('mail_username')
                            ->label('Usuario')
                            ->placeholder('Ej: avisos@miempresa.com.py')
                            ->maxLength(190)
                            ->autocomplete(false),

                        TextInput::make('mail_password')
                            ->label('Clave')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(190)
                            ->placeholder(fn (): string => filled(app(GeneralSettings::class)->mail_password) ? 'Guardada (dejar vacío para no cambiarla)' : 'Clave de la casilla')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->helperText('Se guarda cifrada y no se vuelve a mostrar.'),

                        TextInput::make('mail_from_address')
                            ->label('Correo remitente')
                            ->email()
                            ->placeholder('Ej: avisos@miempresa.com.py')
                            ->maxLength(190)
                            ->required(fn (Get $get): bool => (bool) $get('mail_enabled'))
                            ->helperText('Debe ser una casilla que el servidor permita usar para enviar.'),

                        TextInput::make('mail_from_name')
                            ->label('Nombre del remitente')
                            ->placeholder('Ej: Nominapp')
                            ->maxLength(190),
                    ])
                    ->columns(2),

                Section::make('Auditoría')
                    ->description('Retención del historial de cambios (tabla de auditoría)')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        TextInput::make('audit_retention_months')
                            ->label('Retención del historial')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(120)
                            ->default(24)
                            ->suffix('meses')
                            ->helperText('Los registros de auditoría con más antigüedad se eliminan automáticamente cada noche. 0 = conservar siempre.'),
                    ]),
            ]);
    }
}
