<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

class ManageGeneralSettings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuración General';

    protected static ?string $title = 'Configuración General';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static string $settings = GeneralSettings::class;

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
                        TextInput::make('terminal_stale_threshold_hours')
                            ->label('Umbral de desconexión')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(72)
                            ->default(2)
                            ->suffix('horas')
                            ->helperText('Horas sin heartbeat exitoso antes de marcar un terminal como desconectado en el panel'),
                    ]),
            ]);
    }
}
