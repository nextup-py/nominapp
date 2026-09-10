<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('terminal-inactive muestra el nombre del terminal y su sucursal', function () {
    $company = Company::create([
        'name' => 'Empresa Test',
        'ruc' => '80012345-6',
        'employer_number' => '123456',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $terminal = Terminal::create([
        'branch_id' => $branch->id,
        'name' => 'Terminal 1',
        'code' => 'ABC123',
        'status' => 'inactive',
    ]);

    $html = (string) view('attendances.terminal-inactive', [
        'title' => 'Terminal fuera de servicio',
        'terminal' => $terminal,
    ])->render();

    expect($html)->toContain('Terminal fuera de servicio')
        ->toContain('Terminal 1')
        ->toContain('Sucursal Centro');
});

it('terminal-setup-invalid muestra el mensaje de enlace inválido', function () {
    $html = (string) view('attendances.terminal-setup-invalid')->render();

    expect($html)->toContain('Enlace de configuración inválido')
        ->toContain('Solicite un nuevo enlace');
});

it('enrollments.already-submitted muestra el mensaje de registro enviado', function () {
    $html = (string) view('enrollments.already-submitted')->render();

    expect($html)->toContain('Su rostro ya fue registrado')
        ->toContain('pendiente de aprobación');
});

it('enrollments.expired muestra el mensaje de enlace expirado', function () {
    $html = (string) view('enrollments.expired')->render();

    expect($html)->toContain('Este enlace ya no es válido')
        ->toContain('Contacte al administrador');
});
