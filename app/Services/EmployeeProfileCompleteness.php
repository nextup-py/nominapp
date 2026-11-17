<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;

/**
 * Qué le falta a la ficha de un empleado. El catálogo de requisitos vive acá una sola vez; cada empresa elige cuáles
 * exige (`companies.profile_requirements`, null = los de fábrica). La condición equivalente en SQL está en
 * `Employee::scopeMissingProfileItem()`: mantener ambas en sincronía (un test las compara).
 */
class EmployeeProfileCompleteness
{
    /**
     * Catálogo: clave => etiqueta y si se exige de fábrica.
     *
     * @return array<string, array{label: string, default: bool}>
     */
    public static function items(): array
    {
        return [
            'phone' => ['label' => 'Teléfono', 'default' => true],
            'birth_date' => ['label' => 'Fecha de nacimiento', 'default' => true],
            'address' => ['label' => 'Domicilio', 'default' => true],
            'photo' => ['label' => 'Foto', 'default' => true],
            'face' => ['label' => 'Rostro enrolado', 'default' => true],
            'bank_account' => ['label' => 'Cuenta bancaria (si cobra por transferencia)', 'default' => true],
            'email' => ['label' => 'Correo', 'default' => false],
            'signed_contract' => ['label' => 'Contrato firmado (PDF cargado)', 'default' => false],
        ];
    }

    /**
     * Etiquetas por clave.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (array $item): string => $item['label'], self::items());
    }

    /**
     * Claves que se exigen en una empresa.
     *
     * @return list<string>
     */
    public static function enabledFor(?Company $company): array
    {
        $configured = $company?->profile_requirements;

        if (! is_array($configured)) {
            return array_keys(array_filter(self::items(), fn (array $item): bool => $item['default']));
        }

        return array_values(array_intersect(array_keys(self::items()), $configured));
    }

    /**
     * Claves exigidas que el empleado no cumple. Usa los `*_exists` del listado si vienen precargados.
     *
     * @return list<string>
     */
    public static function missing(Employee $employee): array
    {
        $missing = [];

        foreach (self::enabledFor($employee->branch?->company) as $key) {
            if (self::isMissing($employee, $key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Porcentaje de requisitos cumplidos (100 si la empresa no exige ninguno).
     */
    public static function percent(Employee $employee): int
    {
        $total = count(self::enabledFor($employee->branch?->company));

        return $total === 0 ? 100 : (int) round(($total - count(self::missing($employee))) / $total * 100);
    }

    /**
     * Si un requisito puntual falta en el empleado.
     */
    private static function isMissing(Employee $employee, string $key): bool
    {
        $contract = $employee->activeContract;

        return match ($key) {
            'phone' => blank($employee->phone),
            'birth_date' => $employee->birth_date === null,
            'address' => ! ($employee->addresses_exists ?? $employee->addresses()->exists()),
            'photo' => blank($employee->photo),
            'face' => blank($employee->face_descriptor),
            'email' => blank($employee->email),
            'bank_account' => $contract?->payment_method === 'transfer'
                && ! ($employee->bank_accounts_exists ?? $employee->bankAccounts()->exists()),
            'signed_contract' => $contract !== null && blank($contract->document_path),
            default => false,
        };
    }
}
