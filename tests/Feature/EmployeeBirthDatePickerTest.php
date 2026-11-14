<?php

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el calendario de «Fecha de nacimiento» abría en el mes actual, donde todos los días
 * quedan deshabilitados por la regla de mayoría de edad, y parecía un campo bloqueado.
 */
it('abre el calendario de fecha de nacimiento en una fecha que se puede elegir', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $picker = Livewire::test(CreateEmployee::class)
        ->instance()
        ->form
        ->getComponent('data.birth_date');

    expect($picker->getDefaultFocusedDate())->not->toBeNull()
        ->and(Carbon::parse($picker->getDefaultFocusedDate())->lte(Carbon::parse($picker->getMaxDate())))->toBeTrue();
});
