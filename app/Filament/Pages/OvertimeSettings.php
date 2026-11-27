<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Settings\GeneralSettings;
use App\Settings\PayrollSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Configuración de horas extra en un solo lugar: multiplicadores y topes (ajustes de nómina), tolerancia, permiso por
 * defecto y motivo al salir (ajustes generales). Antes estaban repartidos entre «Configuración de nómina» y
 * «Configuración general». Solo Super Admin, como ambas.
 */
class OvertimeSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use UsesNavigationLayout;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Horas extra';

    protected static ?string $title = 'Configuración de horas extra';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'configuracion-horas-extra';

    protected static string $view = 'filament.pages.overtime-settings';

    /** @var array<string, mixed> Estado del formulario. */
    public ?array $data = [];

    /** Acceso restringido a Super Admin (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    /** Carga los valores actuales de ambos grupos de ajustes. */
    public function mount(): void
    {
        $payroll = app(PayrollSettings::class);
        $general = app(GeneralSettings::class);

        $this->form->fill([
            'overtime_multiplier_diurno' => $payroll->overtime_multiplier_diurno,
            'overtime_multiplier_nocturno' => $payroll->overtime_multiplier_nocturno,
            'overtime_multiplier_holiday' => $payroll->overtime_multiplier_holiday,
            'overtime_multiplier_nocturno_holiday' => $payroll->overtime_multiplier_nocturno_holiday,
            'overtime_max_daily_hours' => $payroll->overtime_max_daily_hours,
            'overtime_max_weekly_hours' => $payroll->overtime_max_weekly_hours,
            'attendance_overtime_tolerance_minutes' => $general->attendance_overtime_tolerance_minutes,
            'attendance_overtime_allowed_default' => $general->attendance_overtime_allowed_default,
            'attendance_overtime_reason_required' => $general->attendance_overtime_reason_required,
            'attendance_overtime_reason_timeout_seconds' => $general->attendance_overtime_reason_timeout_seconds,
            'attendance_overtime_reasons' => array_values($general->attendance_overtime_reasons),
        ]);
    }

    /** Define el formulario. */
    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Multiplicadores')
                    ->description('Factores de cálculo según el Código del Trabajo (Art. 234). Se aplican solo a las horas extra aprobadas.')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Grid::make(2)->schema([
                            $this->multiplier('overtime_multiplier_diurno', 'HE Diurnas', '50% de recargo sobre la hora diurna: 1.5x'),
                            $this->multiplier('overtime_multiplier_nocturno', 'HE Nocturnas (día regular)', '1.30 × 2.0 sobre la base diurna: 2.6x'),
                            $this->multiplier('overtime_multiplier_holiday', 'HE Diurnas feriado/domingo', '100% de recargo sobre la hora diurna: 2.0x'),
                            $this->multiplier('overtime_multiplier_nocturno_holiday', 'HE Nocturnas feriado/domingo', '1.30 × 2.0 sobre la base diurna: 2.6x'),
                        ]),
                    ]),

                Section::make('Límites')
                    ->description('Límites legales de horas extraordinarias (Art. 202 CLT).')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('overtime_max_daily_hours')
                                ->label('Máximo de horas extra por día')
                                ->numeric()->integer()->minValue(1)->maxValue(24)->required()
                                ->suffix('hrs')
                                ->helperText('Límite legal: 3 hrs por día.'),
                            TextInput::make('overtime_max_weekly_hours')
                                ->label('Máximo de horas extra por semana')
                                ->numeric()->integer()->minValue(1)->maxValue(168)->required()
                                ->suffix('hrs')
                                ->helperText('Límite legal: 9 hrs por semana.'),
                        ]),
                    ]),

                Section::make('Registro y permiso')
                    ->description('Cuándo se considera hora extra y quién está autorizado por defecto. Aplica a las jornadas que se calculen desde ahora.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        TextInput::make('attendance_overtime_tolerance_minutes')
                            ->label('Tolerancia de horas extra')
                            ->numeric()->integer()->required()->minValue(0)->maxValue(120)
                            ->suffix('minutos')
                            ->helperText('Hasta este margen por encima del horario no es hora extra. Pasado el margen cuenta todo el tiempo extra. 0 = sin tolerancia.'),
                        Toggle::make('attendance_overtime_allowed_default')
                            ->label('Autorizar horas extra por defecto')
                            ->helperText('Para quienes no lo tienen definido en su ficha, cargo, sucursal o empresa. Apagado: las horas extra se registran igual pero quedan como no autorizadas y no aparecen en Por resolver.'),
                    ]),

                Section::make('Motivo al marcar la salida')
                    ->description('Cuando la salida deja horas extra, el terminal y el celular piden el motivo.')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->schema([
                        Toggle::make('attendance_overtime_reason_required')
                            ->label('Motivo obligatorio')
                            ->live()
                            ->helperText('Encendido: el selector no vence y no se registra la salida hasta elegir un motivo. Apagado: si nadie elige, la salida se registra sin motivo.'),
                        TextInput::make('attendance_overtime_reason_timeout_seconds')
                            ->label('Espera del selector')
                            ->numeric()->integer()->required()->minValue(5)->maxValue(120)
                            ->suffix('segundos')
                            ->hidden(fn (Get $get): bool => (bool) $get('attendance_overtime_reason_required'))
                            ->helperText('Tiempo que espera el selector antes de registrar la salida sin motivo.'),
                        Repeater::make('attendance_overtime_reasons')
                            ->label('Motivos disponibles')
                            ->schema([
                                Hidden::make('key'),
                                TextInput::make('label')->label('Motivo')->required()->maxLength(40),
                                Toggle::make('active')->label('Activo')->default(true)->inline(false),
                            ])
                            ->columns(2)
                            ->reorderableWithButtons()
                            ->deletable(false)
                            ->addActionLabel('Agregar motivo')
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->minItems(1)
                            ->helperText('Un motivo no se elimina: se desactiva, así las marcaciones anteriores siguen mostrándolo. El orden es el que ve el empleado.'),
                    ]),
            ]);
    }

    /**
     * Campo numérico de un multiplicador.
     */
    private function multiplier(string $name, string $label, string $help): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()->minValue(1)->maxValue(10)->step(0.1)->required()
            ->suffix('x')
            ->helperText($help);
    }

    /**
     * Normaliza el catálogo: asigna una clave estable a los motivos nuevos y devuelve null si no es válido.
     *
     * @param  array<int, array<string, mixed>>  $reasons
     * @return array<int, array{key: string, label: string, active: bool}>|null
     */
    public static function normalizeReasons(array $reasons): ?array
    {
        $used = collect($reasons)->pluck('key')->filter()->all();
        $result = [];

        foreach ($reasons as $reason) {
            $label = trim((string) ($reason['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $key = $reason['key'] ?? null;
            if (blank($key)) {
                $base = Str::slug($label, '_') ?: 'motivo';
                $key = $base;
                for ($i = 2; in_array($key, $used, true); $i++) {
                    $key = $base.'_'.$i;
                }
                $used[] = $key;
            }

            $result[] = ['key' => (string) $key, 'label' => $label, 'active' => (bool) ($reason['active'] ?? true)];
        }

        $labels = array_map(fn (array $r): string => mb_strtolower($r['label']), $result);
        $hasActive = collect($result)->contains(fn (array $r): bool => $r['active']);

        return $hasActive && count($labels) === count(array_unique($labels)) ? $result : null;
    }

    /** Guarda los ajustes de nómina y de asistencia. */
    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        $reasons = self::normalizeReasons($data['attendance_overtime_reasons'] ?? []);
        if ($reasons === null) {
            Notification::make()->danger()
                ->title('Motivos no válidos')
                ->body('Debe haber al menos un motivo activo y no puede haber dos con el mismo nombre.')
                ->send();

            return;
        }

        $payroll = app(PayrollSettings::class);
        foreach (['overtime_multiplier_diurno', 'overtime_multiplier_nocturno', 'overtime_multiplier_holiday', 'overtime_multiplier_nocturno_holiday'] as $field) {
            $payroll->{$field} = (float) $data[$field];
        }
        $payroll->overtime_max_daily_hours = (int) $data['overtime_max_daily_hours'];
        $payroll->overtime_max_weekly_hours = (int) $data['overtime_max_weekly_hours'];
        $payroll->save();

        $general = app(GeneralSettings::class);
        $general->attendance_overtime_tolerance_minutes = (int) $data['attendance_overtime_tolerance_minutes'];
        $general->attendance_overtime_allowed_default = (bool) $data['attendance_overtime_allowed_default'];
        $general->attendance_overtime_reason_required = (bool) $data['attendance_overtime_reason_required'];
        $general->attendance_overtime_reason_timeout_seconds = (int) ($data['attendance_overtime_reason_timeout_seconds'] ?? $general->attendance_overtime_reason_timeout_seconds);
        $general->attendance_overtime_reasons = $reasons;
        $general->save();

        $this->form->fill(array_merge($data, ['attendance_overtime_reasons' => $reasons]));

        Notification::make()->success()->title('Configuración guardada')->send();
    }

    /**
     * Botón de guardado del formulario.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Guardar cambios')->submit('save'),
        ];
    }
}
