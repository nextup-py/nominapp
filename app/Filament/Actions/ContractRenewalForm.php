<?php

namespace App\Filament\Actions;

use App\Models\Contract;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

/**
 * Formulario de la acción «Renovar» de un contrato. Lo comparten el listado, el detalle y la edición, y concentra
 * las reglas de la renovación para que no diverjan entre pantallas: inicio sugerido, duración máxima de un año
 * (Art. 53 CLT) y salario mínimo.
 */
class ContractRenewalForm
{
    /** Tipos de contrato cuya duración no puede pasar de un año (misma regla que al crear). */
    private const MAX_ONE_YEAR_TYPES = ['plazo_fijo', 'aprendizaje'];

    /**
     * Componentes del formulario de renovación.
     *
     * @return array<int, Component>
     */
    public static function fields(Contract $record): array
    {
        $becomesIndefinite = $record->wouldBecomeIndefiniteOnRenewal();

        return [
            DatePicker::make('start_date')
                ->label('Fecha de Inicio')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->required()
                ->live()
                ->default(self::defaultStartDate($record))
                ->helperText('Por defecto, el día siguiente al fin del contrato anterior, para no dejar un hueco ni superponer dos contratos.')
                ->closeOnDateSelection(),

            DatePicker::make('end_date')
                ->label('Fecha de Finalización')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->closeOnDateSelection()
                ->visible(! $becomesIndefinite)
                ->required(! $becomesIndefinite)
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                        $error = self::endDateError($record, $get('start_date'), $value);

                        if ($error !== null) {
                            $fail($error);
                        }
                    },
                ])
                ->helperText($record->type === 'plazo_fijo' ? 'Art. 53 CLT: Máximo 1 año' : null),

            TextInput::make('salary')
                ->label($record->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                ->numeric()
                ->required()
                ->prefix('Gs.')
                ->suffix($record->salary_type === 'jornal' ? '/día' : '/mes')
                ->default($record->salary)
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $error = self::salaryError($record, $value);

                        if ($error !== null) {
                            $fail($error);
                        }
                    },
                ])
                ->helperText(self::salaryHint($record)),

            Textarea::make('notes')
                ->label('Notas')
                ->placeholder('Notas sobre la renovación...')
                ->rows(2),
        ];
    }

    /**
     * Inicio sugerido de la renovación: el día siguiente al fin del contrato que se renueva, o hoy si no tiene fin.
     */
    public static function defaultStartDate(Contract $record): Carbon
    {
        return $record->end_date
            ? $record->end_date->copy()->addDay()
            : Carbon::today();
    }

    /**
     * Mensaje de error si la fecha de fin incumple la duración máxima de un año o no es posterior al inicio;
     * null si es válida. Solo aplica a plazo fijo y aprendizaje, igual que al crear el contrato.
     */
    public static function endDateError(Contract $record, mixed $startDate, mixed $endDate): ?string
    {
        if (! in_array($record->type, self::MAX_ONE_YEAR_TYPES, true) || blank($startDate) || blank($endDate)) {
            return null;
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lte($start)) {
            return 'La fecha de finalización debe ser posterior a la fecha de inicio.';
        }

        if ($end->gt($start->copy()->addYear())) {
            return 'Art. 53 CLT: los contratos a plazo determinado no pueden exceder 1 año.';
        }

        return null;
    }

    /**
     * Mensaje de error si el salario es menor al mínimo legal vigente y además cambia respecto del contrato que se
     * renueva. Renovar con el mismo salario no se bloquea aunque esté bajo el mínimo (decisión de la empresa: queda
     * como aviso); lo que se bloquea es fijar un salario nuevo por debajo del mínimo.
     */
    public static function salaryError(Contract $record, mixed $salary): ?string
    {
        if (! is_numeric($salary)) {
            return null;
        }

        $min = Contract::getMinSalaryFor($record->salary_type);

        if ((float) $salary >= $min || (float) $salary === (float) $record->salary) {
            return null;
        }

        return 'El salario no puede ser menor al mínimo legal vigente (Gs. '.number_format($min, 0, ',', '.').').';
    }

    /**
     * Aviso bajo el campo de salario cuando el contrato actual ya está por debajo del mínimo legal.
     */
    public static function salaryHint(Contract $record): ?string
    {
        $min = Contract::getMinSalaryFor($record->salary_type);

        if ((float) $record->salary >= $min) {
            return null;
        }

        return 'Atención: el salario actual está por debajo del mínimo legal (Gs. '.number_format($min, 0, ',', '.').'). Se puede renovar con el mismo valor, pero uno nuevo debe llegar al mínimo.';
    }
}
