<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeManifestTestTerminal(): Terminal
{
    $company = Company::create(['name' => 'Empresa Manifest', 'ruc' => '90000001-1', 'employer_number' => 900001]);
    $branch = Branch::create(['name' => 'Sucursal Manifest', 'company_id' => $company->id]);

    return Terminal::create(['name' => 'Terminal Manifest', 'branch_id' => $branch->id, 'code' => 'ABC12345']);
}

it('el manifest de /marcar devuelve JSON válido con el color primario por defecto', function () {
    $response = $this->get('/marcar/manifest.json');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json');
    $response->assertJson([
        'name' => 'Nominapp Marcación',
        'start_url' => '/marcar',
        'scope' => '/marcar',
        'display' => 'standalone',
        'theme_color' => '#0d9488',
        'background_color' => '#f0fdfa',
    ]);
});

it('el manifest de /marcar refleja un color primario distinto del default', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'rose';
    $settings->save();

    $response = $this->get('/marcar/manifest.json');

    $response->assertOk();
    $response->assertJsonPath('theme_color', '#e11d48');
    $response->assertJsonPath('background_color', '#fff1f2');
});

it('el manifest de terminal incluye el start_url específico de esa sucursal', function () {
    $terminal = makeManifestTestTerminal();

    $response = $this->get("/terminal/{$terminal->code}/manifest.json");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json');
    $response->assertJson([
        'name' => 'Nominapp Terminal',
        'start_url' => '/terminal/ABC12345',
        'scope' => '/terminal/',
        'display' => 'standalone',
    ]);
});

it('el manifest de terminal devuelve 404 si el código no existe', function () {
    $response = $this->get('/terminal/NOEXISTE/manifest.json');

    $response->assertNotFound();
});
