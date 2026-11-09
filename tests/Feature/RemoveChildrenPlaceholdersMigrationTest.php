<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeChild;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function childrenMigrationEmployee(int $n, int $declared): Employee
{
    $company = Company::create(['name' => "EmpCM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucCM {$n}", 'company_id' => $company->id]);

    $employee = Employee::create([
        'first_name' => 'Madre', 'last_name' => 'Apellido', 'ci' => (string) $n, 'email' => "cm{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    DB::table('employees')->where('id', $employee->id)->update(['children_count' => $declared]);

    return $employee;
}

it('borra solo el relleno, guarda el CSV de declarados y quita la columna children_count', function () {
    Storage::fake('local');
    Schema::table('employees', function (Blueprint $table) {
        $table->tinyInteger('children_count')->unsigned()->default(0);
    });

    $employee = childrenMigrationEmployee(4700001, 2);

    foreach (['Hijo/a 1', 'Hijo/a 2'] as $name) {
        EmployeeChild::create([
            'employee_id' => $employee->id, 'first_name' => $name, 'last_name' => 'Apellido', 'birth_date' => '2015-01-01',
        ]);
    }
    $edited = EmployeeChild::create([
        'employee_id' => $employee->id, 'first_name' => 'Hijo/a 3', 'last_name' => 'Apellido', 'birth_date' => '2015-01-01', 'ci' => '5551234',
    ]);
    $real = EmployeeChild::create([
        'employee_id' => $employee->id, 'first_name' => 'Lucía', 'last_name' => 'Apellido', 'birth_date' => '2016-03-04',
    ]);

    (require database_path('migrations/2026_11_10_100000_remove_children_placeholders_and_count.php'))->up();

    expect(EmployeeChild::pluck('id')->all())->toEqualCanonicalizing([$edited->id, $real->id])
        ->and(Schema::hasColumn('employees', 'children_count'))->toBeFalse();

    $files = Storage::disk('local')->files();
    expect($files)->toHaveCount(1);
    $csv = Storage::disk('local')->get($files[0]);
    expect($csv)->toContain('4700001')->toContain('"Madre"')->toContain(',2');
});

// El DDL de la migración confirma la transacción de RefreshDatabase: se limpian los datos para no contaminar otros tests.
afterEach(function () {
    DB::table('employee_children')->delete();
    DB::table('employees')->where('ci', '4700001')->delete();
    DB::table('branches')->where('name', 'SucCM 4700001')->delete();
    DB::table('companies')->where('name', 'EmpCM 4700001')->delete();
});
