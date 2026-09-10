<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('la vista de terminal incluye el botón de toggle de tema compartido', function () {
    $company = Company::create([
        'name' => 'Empresa Test',
        'ruc' => '80012345-6',
        'employer_number' => '12345',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $terminal = Terminal::create([
        'branch_id' => $branch->id,
        'name' => 'Terminal 1',
        'code' => 'XYZ789',
        'status' => 'active',
    ]);

    $response = $this->get(route('terminal.show', $terminal->code));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
});
