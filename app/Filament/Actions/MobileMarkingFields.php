<?php

namespace App\Filament\Actions;

use App\Models\Branch;
use App\Models\Employee;
use App\Services\MobileMarkingService;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Piezas compartidas del permiso de marcación desde el celular (empleado, sucursal, empresa):
 * el selector Heredar/Sí/No y la acción masiva de empleados. La cadena de herencia vive en
 * `Employee::mobileMarkingSource()`.
 */
class MobileMarkingFields
{
    /** Valores del selector: `inherit` = null (hereda del nivel siguiente). */
    public static function options(): array
    {
        return [
            'inherit' => 'Heredar',
            'yes' => 'Sí, puede marcar desde el celular',
            'no' => 'No, solo desde el terminal',
        ];
    }

    /** Selector Heredar/Sí/No que guarda null/true/false en `mobile_marking_allowed`. */
    public static function select(string $helperText): Select
    {
        return Select::make('mobile_marking_allowed')
            ->label('Marcación desde el celular')
            ->options(self::options())
            ->default('inherit')
            ->native(false)
            ->selectablePlaceholder(false)
            ->formatStateUsing(fn ($state): string => self::toOption($state))
            ->dehydrateStateUsing(fn ($state): ?bool => self::fromOption($state))
            ->helperText($helperText);
    }

    /** @param  bool|string|null  $value */
    public static function toOption(mixed $value): string
    {
        return match (true) {
            $value === 'yes', $value === 'no', $value === 'inherit' => $value,
            $value === null => 'inherit',
            default => $value ? 'yes' : 'no',
        };
    }

    public static function fromOption(mixed $value): ?bool
    {
        return match ($value) {
            'yes', true, 1, '1' => true,
            'no', false, 0, '0' => false,
            default => null,
        };
    }

    /** Texto del permiso que hereda un nivel (lo que valdría si se deja en «Heredar»). */
    public static function inheritedLabel(?Model $model): string
    {
        $parent = match (true) {
            $model instanceof Employee => $model->branch?->mobile_marking_allowed
                ?? $model->branch?->company?->mobile_marking_allowed,
            $model instanceof Branch => $model->company?->mobile_marking_allowed,
            default => null,
        };

        return ($parent ?? app(GeneralSettings::class)->attendance_mobile_marking_default) ? 'Sí' : 'No';
    }

    /** Acción masiva de Empleados: fija el permiso de los seleccionados y revoca los celulares que dejan de estar habilitados. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('set_mobile_marking')
            ->label('Marcación desde el celular')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('info')
            ->authorize(fn (): bool => auth()->user()?->can('update_employee') ?? false)
            ->form([
                Select::make('value')
                    ->label('Permiso')
                    ->options(self::options())
                    ->native(false)
                    ->required()
                    ->helperText('Si se deshabilita, el celular ya vinculado de cada empleado se revoca de inmediato.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Marcación desde el celular')
            ->modalSubmitActionLabel('Aplicar')
            ->action(function (Collection $records, array $data): void {
                $value = self::fromOption($data['value']);
                $linkedBefore = Employee::query()->whereIn('id', $records->pluck('id'))->whereNotNull('mobile_linked_at')->count();

                Employee::query()->whereIn('id', $records->pluck('id'))->get()->each(
                    fn (Employee $employee) => $employee->update(['mobile_marking_allowed' => $value])
                );

                // `update()` ya revoca por empleado (EmployeeObserver); esto cubre el caso en que el valor
                // no cambió pero el efectivo sí lo prohíbe.
                MobileMarkingService::revokeDisallowed(Employee::query()->whereIn('id', $records->pluck('id')));
                $revoked = $linkedBefore - Employee::query()->whereIn('id', $records->pluck('id'))->whereNotNull('mobile_linked_at')->count();

                Notification::make()
                    ->success()
                    ->title('Permiso actualizado')
                    ->body("{$records->count()} empleado(s) actualizados".($revoked > 0 ? ", {$revoked} celular(es) revocado(s)." : '.'))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
