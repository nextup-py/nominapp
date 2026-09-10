<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('incluye company_name y company_logo en la respuesta exitosa de vinculación', function () {
    $company = Company::create([
        'name' => 'Empresa Test',
        'ruc' => '80012345-6',
        'employer_number' => '90012345',
        'logo_thumbnail' => 'data:image/png;base64,abc123',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $employee = Employee::create([
        'branch_id' => $branch->id,
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'ci' => '1234567',
        'birth_date' => '1990-05-20',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
    ]);

    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '1234567',
        'birth_date' => '1990-05-20',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('employee.company_name', 'Empresa Test');
    $response->assertJsonPath('employee.company_logo', 'data:image/png;base64,abc123');
});

it('company_logo es null si la empresa no tiene logo, sin romper la respuesta', function () {
    $company = Company::create([
        'name' => 'Empresa Sin Logo',
        'ruc' => '80012346-7',
        'employer_number' => '90012346',
    ]);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $employee = Employee::create([
        'branch_id' => $branch->id,
        'first_name' => 'Ana',
        'last_name' => 'García',
        'ci' => '7654321',
        'birth_date' => '1988-02-10',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
    ]);

    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '7654321',
        'birth_date' => '1988-02-10',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('employee.company_name', 'Empresa Sin Logo');
    $response->assertJsonPath('employee.company_logo', null);
});

it('el mensaje de error de datos inválidos se mantiene genérico', function () {
    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '0000000',
        'birth_date' => '2000-01-01',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))
        ->toBe('CI o fecha de nacimiento incorrectos, o el empleado no está habilitado para marcar desde su dispositivo.');
});
