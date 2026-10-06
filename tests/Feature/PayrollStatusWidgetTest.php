<?php

use App\Filament\Widgets\PayrollStatusWidget;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])))));

it('muestra el estado del período con la misma etiqueta que el resto del sistema ("En Proceso")', function () {
    PayrollPeriod::create([
        'name' => 'Octubre 2026',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'frequency' => 'monthly',
        'status' => 'processing',
    ]);

    Livewire::test(PayrollStatusWidget::class)->assertSee('Estado: En Proceso')->assertDontSee('En procesamiento');
});
