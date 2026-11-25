<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Resources\RoleResource;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\UserSessionService;
use App\Support\NavigationLayout;
use App\Support\PasswordPolicy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Support\Collection;

/**
 * Perfil del usuario del panel: foto, contraseña (con la política del proyecto), datos de solo lectura (nombre, correo,
 * ficha de empleado vinculada, roles), sesiones abiertas y últimos ingresos. El nombre y el correo los cambia solo el
 * Super Admin desde Usuarios: son la identidad de acceso y la que queda en las auditorías.
 */
class EditProfile extends BaseEditProfile
{
    protected static string $view = 'filament.pages.auth.edit-profile';

    /** Nombre en solo lectura (se cambia desde Usuarios). */
    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label('Nombre')
            ->disabled()
            ->dehydrated(false);
    }

    /** Correo en solo lectura (se cambia desde Usuarios). */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Correo')
            ->disabled()
            ->dehydrated(false)
            ->helperText('Para cambiar el nombre o el correo, pedíselo al administrador.');
    }

    /** Contraseña con la política del proyecto. */
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->rule(PasswordPolicy::rule())
            ->helperText(PasswordPolicy::hint());
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        Section::make('Perfil')
                            ->icon('heroicon-o-user-circle')
                            ->schema([
                                FileUpload::make('avatar_path')
                                    ->label('Foto de perfil')
                                    ->avatar()
                                    ->image()
                                    ->imageEditor()
                                    ->circleCropper()
                                    ->disk('public')
                                    ->directory('avatars')
                                    ->maxSize(2048)
                                    ->getUploadedFileNameForStorageUsing(fn ($file): string => 'avatar_'.$this->getUser()->id.'_'.now()->format('Y-m-d_H-i-s').'.'.$file->getClientOriginalExtension()),
                                $this->getNameFormComponent(),
                                $this->getEmailFormComponent(),
                                Placeholder::make('employee')
                                    ->label('Ficha de empleado')
                                    ->content(fn (): string => $this->employeeSummary()),
                                Placeholder::make('roles')
                                    ->label('Roles')
                                    ->content(fn (): string => $this->getUser()->roles->pluck('name')->implode(', ') ?: 'Sin roles'),
                            ]),
                        Section::make('Preferencias')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Radio::make('navigation_layout')
                                    ->label('Menú del panel')
                                    ->options(NavigationLayout::options())
                                    ->descriptions([
                                        NavigationLayout::SIDEBAR => 'Menú vertical a la izquierda, con los grupos plegables.',
                                        NavigationLayout::TOP => 'Menú horizontal arriba, con menos grupos y desplegables.',
                                    ])
                                    ->default(NavigationLayout::SIDEBAR)
                                    ->in(array_keys(NavigationLayout::options()))
                                    ->required(),
                            ]),
                        Section::make('Contraseña')
                            ->icon('heroicon-o-key')
                            ->description('Dejá estos campos vacíos para conservar la actual.')
                            ->schema([
                                $this->getPasswordFormComponent(),
                                $this->getPasswordConfirmationFormComponent(),
                            ]),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    /** Recarga el perfil completo para que el menú se redibuje con el formato elegido. */
    protected function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }

    /** Resumen de la ficha de empleado vinculada, o aviso si no hay. */
    private function employeeSummary(): string
    {
        $employee = $this->getUser()->employee;

        if (! $employee) {
            return 'Sin ficha de empleado vinculada';
        }

        return collect([
            $employee->full_name,
            $employee->activeContract?->position?->name,
            $employee->branch?->name,
            $employee->reportsTo ? 'Reporta a '.$employee->reportsTo->full_name : null,
        ])->filter()->implode(' · ');
    }

    /**
     * Permisos del usuario agrupados por módulo (solo lectura).
     *
     * @return array<string, list<string>>
     */
    public function permissionGroups(): array
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->hasRole('Super Admin')) {
            return [];
        }

        $granted = $user->getAllPermissions()->pluck('name')->flip();
        $groups = [];

        foreach (RoleResource::permissionOptions() as $group => $options) {
            $labels = array_values(array_intersect_key($options, $granted->all()));

            if ($labels !== []) {
                $groups[$group] = $labels;
            }
        }

        return $groups;
    }

    /** Indica si el usuario tiene acceso total (Super Admin). */
    public function isSuperAdmin(): bool
    {
        return $this->getUser()->hasRole('Super Admin');
    }

    /**
     * Sesiones abiertas del usuario.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sessions(): Collection
    {
        return UserSessionService::forUser($this->getUser(), session()->getId());
    }

    /**
     * Últimos ingresos del usuario.
     *
     * @return Collection<int, LoginHistory>
     */
    public function logins(): Collection
    {
        return $this->getUser()->loginHistories()->limit(10)->get();
    }

    /** Cierra las sesiones abiertas en otros dispositivos. */
    public function closeOtherSessions(): void
    {
        $closed = UserSessionService::closeOthers($this->getUser(), session()->getId());

        Notification::make()
            ->success()
            ->title($closed > 0 ? "Se cerraron {$closed} sesión(es)" : 'No había otras sesiones abiertas')
            ->send();
    }
}
