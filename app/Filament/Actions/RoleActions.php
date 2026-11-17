<?php

namespace App\Filament\Actions;

use App\Filament\Resources\RoleResource;
use App\Support\SuggestedRoles;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

/**
 * Acciones del recurso de roles: duplicar un rol, compararlo con otro y crear los roles sugeridos que falten.
 * Todas son solo para quien puede crear roles (Super Admin).
 */
class RoleActions
{
    /**
     * Acción de fila: crea una copia del rol, con sus permisos, bajo otro nombre.
     */
    public static function duplicate(): TableAction
    {
        return TableAction::make('duplicate')
            ->label('Duplicar')
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn () => RoleResource::canCreate())
            ->modalHeading(fn (Role $record) => "Duplicar el rol «{$record->name}»")
            ->modalDescription('Se crea un rol nuevo con los mismos permisos para ajustarlo después.')
            ->fillForm(fn (Role $record) => ['name' => $record->name.' (copia)', 'description' => $record->description])
            ->form([
                TextInput::make('name')
                    ->label('Nombre del rol nuevo')
                    ->required()
                    ->maxLength(255)
                    ->unique(table: Role::class, column: 'name')
                    ->validationMessages(['unique' => 'Ya existe un rol con ese nombre.']),
                Textarea::make('description')->label('Descripción')->rows(2)->maxLength(500),
            ])
            ->modalSubmitActionLabel('Sí, duplicar')
            ->action(function (Role $record, array $data): void {
                $copy = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'description' => $data['description'] ?? null]);
                $copy->syncPermissions($record->permissions);

                Notification::make()->success()->title("Rol «{$copy->name}» creado")->body("Copia de «{$record->name}» con {$copy->permissions()->count()} permisos.")->send();
            });
    }

    /**
     * Acción del detalle: compara el rol con otro y muestra qué tiene cada uno que el otro no.
     */
    public static function compare(): PageAction
    {
        return PageAction::make('compare')
            ->label('Comparar con otro rol')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->modalHeading('Comparar roles')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->form(fn ($livewire) => [
                Select::make('other_id')
                    ->label('Comparar con')
                    ->options(fn () => Role::query()->whereKeyNot($livewire->getRecord()->getKey())->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable()
                    ->native(false)
                    ->live(),

                Placeholder::make('diff')
                    ->label('')
                    ->content(fn (Get $get) => self::diffHtml($livewire->getRecord(), $get('other_id'))),
            ]);
    }

    /**
     * Acción de encabezado del listado: crea los roles sugeridos que todavía no existen.
     */
    public static function createSuggested(): PageAction
    {
        $missing = fn () => array_values(array_diff(array_keys(SuggestedRoles::all()), Role::query()->pluck('name')->all()));

        return PageAction::make('create_suggested_roles')
            ->label('Crear roles sugeridos')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn () => RoleResource::canCreate() && $missing() !== [])
            ->requiresConfirmation()
            ->modalHeading('Crear roles sugeridos')
            ->modalDescription(fn () => 'Se crearán los roles que faltan, con su descripción y permisos: '.implode(', ', $missing()).'. No se modifica ningún rol existente.')
            ->modalSubmitActionLabel('Sí, crear')
            ->action(function (): void {
                $created = SuggestedRoles::createMissing();

                Notification::make()->success()->title(count($created).' rol(es) creado(s)')->body(implode(', ', $created).'.')->send();
            });
    }

    /**
     * Diferencias de permisos entre dos roles.
     */
    private static function diffHtml(Role $role, mixed $otherId): HtmlString
    {
        if (blank($otherId) || ! ($other = Role::query()->find($otherId))) {
            return new HtmlString('<span style="color: rgb(var(--gray-500));">Elegí un rol para ver en qué se diferencian.</span>');
        }

        $labels = collect(RoleResource::permissionOptions())->collapse();
        $mine = $role->permissions->pluck('name');
        $theirs = $other->permissions->pluck('name');
        $label = fn (string $name) => e($labels[$name] ?? $name);

        $block = function (string $title, $names) use ($label): string {
            $html = '<div style="margin-top: .75rem;"><strong>'.e($title).' ('.$names->count().')</strong>';
            $html .= $names->isEmpty()
                ? '<div style="color: rgb(var(--gray-500));">Nada</div>'
                : '<div style="font-size: .8rem; line-height: 1.6;">'.$names->map($label)->implode(' · ').'</div>';

            return $html.'</div>';
        };

        return new HtmlString(
            '<div style="font-size: .875rem;">'
            .$block("Solo «{$role->name}»", $mine->diff($theirs))
            .$block("Solo «{$other->name}»", $theirs->diff($mine))
            .'<div style="margin-top: .75rem; color: rgb(var(--gray-500));">'.$mine->intersect($theirs)->count().' permisos en común.</div></div>'
        );
    }
}
