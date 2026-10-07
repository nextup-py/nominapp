<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Empresa mínima válida (solo los campos obligatorios de la base de datos).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'ruc' => fake()->unique()->numerify('########').'-'.fake()->randomDigit(),
            'employer_number' => fake()->unique()->numberBetween(1, 99999999),
            'is_active' => true,
        ];
    }

    /** Empresa con todos los datos legales y de contacto cargados. */
    public function complete(): static
    {
        return $this->state(fn () => [
            'trade_name' => fake()->company(),
            'legal_type' => 'SA',
            'legal_rep_name' => fake()->name(),
            'legal_rep_ci' => fake()->numberBetween(1000000, 9999999),
            'address' => fake()->streetAddress(),
            'city' => 'Asunción',
            'phone' => '0981123456',
            'email' => fake()->companyEmail(),
            'logo' => 'companies/logos/logo.png',
        ]);
    }

    /** Empresa inactiva. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
