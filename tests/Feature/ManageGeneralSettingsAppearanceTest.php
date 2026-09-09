<?php

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('guarda el color primario y la fuente elegidos desde la sección Apariencia', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm([
            'primary_color' => 'indigo',
            'font' => 'inter',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class);

    expect($settings->primary_color)->toBe('indigo')
        ->and($settings->font)->toBe('inter');
});

it('rechaza un color primario fuera de la lista curada', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['primary_color' => 'not-a-real-color'])
        ->call('save')
        ->assertHasFormErrors(['primary_color']);
});
