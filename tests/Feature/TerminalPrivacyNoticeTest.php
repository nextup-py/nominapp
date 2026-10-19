<?php

use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function privacyTerminal(array $company = []): Terminal
{
    static $n = 9700000;
    $n++;

    $company = Company::create(['name' => "EmpPV {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n, ...$company]);
    $branch = Branch::create(['name' => "SucPV {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => "Terminal PV {$n}", 'branch_id' => $branch->id]);
}

function privacyHeartbeat(Terminal $terminal): array
{
    Sanctum::actingAs($terminal, ['terminal:sync']);

    return test()->postJson('/api/v1/terminal/heartbeat')->assertOk()->json('config');
}

it('sin aviso cargado el payload es null y el latido manda privacy null', function () {
    $terminal = privacyTerminal();

    expect($terminal->branch->company->privacyNoticePayload())->toBeNull();
    expect(privacyHeartbeat($terminal)['privacy'])->toBeNull();
});

it('con texto y enlace el payload trae versión y QR del enlace', function () {
    $terminal = privacyTerminal(['privacy_notice' => '  Usamos su rostro solo para asistencia.  ', 'privacy_policy_url' => 'https://empresa.com.py/privacidad']);

    $payload = $terminal->branch->company->privacyNoticePayload();

    expect($payload['text'])->toBe('Usamos su rostro solo para asistencia.')
        ->and($payload['url'])->toBe('https://empresa.com.py/privacidad')
        ->and($payload['version'])->toBe(1)
        ->and($payload['qr'])->toStartWith('data:image/svg+xml;base64,');
    expect(privacyHeartbeat($terminal)['privacy']['text'])->toBe('Usamos su rostro solo para asistencia.');
});

it('con solo texto no genera QR', function () {
    $company = privacyTerminal(['privacy_notice' => 'Solo texto'])->branch->company;

    expect($company->privacyNoticePayload()['qr'])->toBeNull();
});

it('la versión sube al cambiar el texto o el enlace y no con otros cambios', function () {
    $company = privacyTerminal(['privacy_notice' => 'v1'])->branch->company;

    $company->update(['city' => 'Asunción']);
    expect($company->fresh()->privacy_notice_version)->toBe(1);

    $company->update(['privacy_notice' => 'v2']);
    expect($company->fresh()->privacy_notice_version)->toBe(2);

    $company->update(['privacy_policy_url' => 'https://x.py/p']);
    expect($company->fresh()->privacy_notice_version)->toBe(3);
});

it('la página del terminal entrega el aviso en window.terminalData', function () {
    $terminal = privacyTerminal(['privacy_notice' => 'Aviso visible en pantalla']);

    $this->get("/terminal/{$terminal->code}")
        ->assertOk()
        ->assertSee('Aviso visible en pantalla', false)
        ->assertSee('id="privacyModal"', false)
        ->assertSee('id="btnPrivacyInfo"', false);
});

it('el formulario de la empresa guarda el aviso y rechaza un enlace inválido', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $company = Company::factory()->complete()->create();

    Livewire::actingAs($admin)
        ->test(EditCompany::class, ['record' => $company->getKey()])
        ->fillForm(['privacy_notice' => 'Texto', 'privacy_policy_url' => 'no-es-un-enlace'])
        ->call('save')
        ->assertHasFormErrors(['privacy_policy_url' => 'url']);

    Livewire::actingAs($admin)
        ->test(EditCompany::class, ['record' => $company->getKey()])
        ->fillForm(['privacy_notice' => 'Texto', 'privacy_policy_url' => 'https://empresa.com.py/privacidad'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()->privacy_notice)->toBe('Texto');
});
