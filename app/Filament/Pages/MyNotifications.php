<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\NotificationCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * «Mis notificaciones»: cada usuario elige qué avisos quiere recibir, por qué canal (campanita,
 * correo) y con qué frecuencia (al instante o en un resumen diario a la hora que prefiera).
 * Solo se muestran los avisos de los módulos a los que tiene acceso. Se abre desde el menú de
 * usuario (no figura en la barra lateral) y no exige permisos: cada quien edita lo suyo.
 */
class MyNotifications extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'Mis notificaciones';

    protected static ?string $slug = 'mis-notificaciones';

    protected static string $view = 'filament.pages.my-notifications';

    /** @var array<string, mixed>|null Estado del formulario: `digest_hour` y `rows` por clave de aviso. */
    public ?array $data = [];

    /** No aparece en la barra lateral: se llega desde el menú de usuario. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** Carga las preferencias efectivas del usuario en el formulario. */
    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    /**
     * Estado inicial del formulario con los valores efectivos (por defecto o elegidos).
     *
     * @return array<string, mixed>
     */
    protected function currentState(): array
    {
        $user = $this->user();
        $service = app(NotificationPreferenceService::class);
        $rows = [];

        foreach (array_keys(NotificationCatalog::forUser($user)) as $key) {
            $rows[self::stateKey($key)] = $service->resolve($user, $key);
        }

        return [
            'digest_hour' => (int) $user->notification_digest_hour,
            'rows' => $rows,
        ];
    }

    public function form(Form $form): Form
    {
        $entries = NotificationCatalog::forUser($this->user());

        $sections = [
            Section::make('Resumen diario')
                ->description('Los avisos que elijas recibir «en el resumen diario» se juntan y te llegan una vez al día, a esta hora.')
                ->icon('heroicon-o-inbox-stack')
                ->schema([
                    Select::make('digest_hour')
                        ->label('Hora del resumen')
                        ->options(collect(range(0, 23))->mapWithKeys(fn (int $hour) => [$hour => sprintf('%02d:00', $hour)])->all())
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->required(),
                ]),
        ];

        foreach (NotificationCatalog::categories() as $category => $categoryLabel) {
            $rows = collect($entries)
                ->filter(fn (array $entry) => $entry['category'] === $category)
                ->map(fn (array $entry, string $key) => $this->entryRow($key, $entry))
                ->values()
                ->all();

            if ($rows === []) {
                continue;
            }

            $sections[] = Section::make($categoryLabel)->collapsible()->schema($rows);
        }

        if (count($sections) === 1) {
            $sections[] = Section::make('Avisos')->schema([
                Placeholder::make('empty')->hiddenLabel()->content('Tu rol no recibe avisos que se puedan configurar.'),
            ]);
        }

        return $form->schema($sections)->statePath('data');
    }

    /**
     * Fila de un aviso: interruptor, canales y frecuencia.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function entryRow(string $key, array $entry): Grid
    {
        $path = 'rows.'.self::stateKey($key);
        $mandatory = (bool) $entry['mandatory'];

        return Grid::make(['default' => 1, 'lg' => 12])->schema([
            Toggle::make("{$path}.enabled")
                ->label($entry['label'])
                ->helperText($entry['description'].($mandatory ? ' Es obligatorio: no se puede desactivar.' : ''))
                ->disabled($mandatory)
                ->dehydrated()
                ->live()
                ->columnSpan(['lg' => 6]),
            CheckboxList::make("{$path}.channels")
                ->label('Canal')
                ->options(NotificationCatalog::channelOptions())
                ->columns(2)
                ->required(fn (Get $get) => (bool) $get("{$path}.enabled"))
                ->validationMessages(['required' => 'Elegí al menos un canal.'])
                ->columnSpan(['lg' => 3]),
            Select::make("{$path}.frequency")
                ->label('Frecuencia')
                ->options(NotificationCatalog::frequencyOptions())
                ->native(false)
                ->selectablePlaceholder(false)
                ->disabled($mandatory)
                ->dehydrated()
                ->columnSpan(['lg' => 3]),
        ]);
    }

    /** Guarda las elecciones: solo las que difieren del valor por defecto quedan en la base. */
    public function save(): void
    {
        $this->form->validate();

        $user = $this->user();
        $service = app(NotificationPreferenceService::class);
        $state = $this->data ?? [];

        foreach (array_keys(NotificationCatalog::forUser($user)) as $key) {
            $row = $state['rows'][self::stateKey($key)] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $service->store(
                $user,
                $key,
                (bool) ($row['enabled'] ?? false),
                array_values((array) ($row['channels'] ?? [])),
                (string) ($row['frequency'] ?? NotificationCatalog::FREQUENCY_INSTANT),
            );
        }

        $hour = (int) ($state['digest_hour'] ?? NotificationCatalog::DEFAULT_DIGEST_HOUR);
        $user->forceFill(['notification_digest_hour' => max(0, min(23, $hour))])->save();

        Notification::make()->success()->title('Preferencias guardadas')->body('Los cambios aplican desde el próximo aviso.')->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Restablecer valores')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Restablecer notificaciones')
                ->modalDescription('Se vuelve a los valores por defecto de todos los avisos y a la hora de resumen de las 08:00.')
                ->modalSubmitActionLabel('Sí, restablecer')
                ->action(function () {
                    $user = $this->user();
                    app(NotificationPreferenceService::class)->reset($user);
                    $user->forceFill(['notification_digest_hour' => NotificationCatalog::DEFAULT_DIGEST_HOUR])->save();
                    $this->form->fill($this->currentState());

                    Notification::make()->success()->title('Valores restablecidos')->send();
                }),
        ];
    }

    /** El punto en la clave del catálogo chocaría con la ruta de estado del formulario. */
    public static function stateKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
