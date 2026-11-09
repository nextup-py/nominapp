<?php

use App\Filament\Actions\ContractRenewalForm;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\ContractService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crea un empleado con empresa, sucursal, departamento y cargo propios.
 * Retorna un array con el employee y los IDs de position/department para pasar a makeContract.
 *
 * @return array{employee: Employee, position_id: int, department_id: int}
 */
function makeContractEmployee(): array
{
    static $ci = 4000000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucC {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepC {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosC {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Contract',
        'ci' => (string) $n,
        'email' => "ctr{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    return [
        'employee' => $employee,
        'position_id' => $position->id,
        'department_id' => $department->id,
    ];
}

/**
 * Crea un contrato para el empleado dado.
 *
 * @param  string  $type  'plazo_fijo' | 'indefinido' | 'obra'
 * @param  string  $status  'active' | 'renewed' | 'terminated'
 */
function makeContract(
    Employee $employee,
    int $positionId,
    int $departmentId,
    string $type = 'plazo_fijo',
    string $status = 'active',
    ?string $endDate = '2026-06-30',
    ?string $notes = null,
): Contract {
    return Contract::create([
        'employee_id' => $employee->id,
        'type' => $type,
        'start_date' => '2026-01-01',
        'end_date' => $endDate,
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'work_modality' => 'presencial',
        'position_id' => $positionId,
        'department_id' => $departmentId,
        'status' => $status,
        'notes' => $notes,
    ]);
}

// ─── terminate() ─────────────────────────────────────────────────────────────

it('terminate establece el status del contrato en terminated', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    ContractService::terminate($contract, 'Fin de proyecto');

    expect($contract->fresh()->status)->toBe('terminated');
});

it('terminate agrega el motivo a las notas cuando notes está vacío', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, notes: null);

    ContractService::terminate($contract, 'Renuncia voluntaria');

    expect($contract->fresh()->notes)->toContain('Renuncia voluntaria');
});

it('terminate concatena el motivo a notas existentes', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, notes: 'Nota previa.');

    ContractService::terminate($contract, 'Vencimiento del plazo');

    $notes = $contract->fresh()->notes;
    expect($notes)->toContain('Nota previa.')
        ->and($notes)->toContain('Vencimiento del plazo');
});

it('terminate usa "Sin motivo especificado" si reason es null', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    ContractService::terminate($contract, null);

    expect($contract->fresh()->notes)->toContain('Sin motivo especificado');
});

// ─── renew() ─────────────────────────────────────────────────────────────────

it('renew marca el contrato original como renewed', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($contract->fresh()->status)->toBe('renewed');
});

it('renew crea un nuevo contrato activo para el empleado', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->status)->toBe('active')
        ->and($renewed->employee_id)->toBe($employee->id)
        ->and($renewed->start_date->toDateString())->toBe('2026-07-01');
});

it('renew hereda salary, salary_type y payroll_type del contrato original', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01']);

    expect((float) $renewed->salary)->toBe(2_550_000.0)
        ->and($renewed->salary_type)->toBe('mensual')
        ->and($renewed->payroll_type)->toBe('monthly');
});

it('renew asigna el usuario autenticado como created_by_id', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01']);

    expect($renewed->created_by_id)->toBe($user->id);
});

it('primera renovación de plazo_fijo mantiene el tipo plazo_fijo', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->type)->toBe('plazo_fijo');
});

it('segunda renovación de plazo_fijo fuerza tipo indefinido (Art. 53 CLT)', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();

    // Contrato 1: plazo_fijo original
    $contract1 = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    // Primera renovación → sigue siendo plazo_fijo
    $contract2 = ContractService::renew($contract1, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);
    expect($contract2->type)->toBe('plazo_fijo');

    // Segunda renovación → debe forzar indefinido
    $contract3 = ContractService::renew($contract2, ['start_date' => '2027-01-01', 'end_date' => '2027-06-30']);

    expect($contract3->type)->toBe('indefinido')
        ->and($contract3->end_date)->toBeNull();
});

it('renovación de contrato indefinido no aplica Art. 53 CLT', function () {
    $user = User::factory()->create();
    Auth::login($user);

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'indefinido', endDate: null);

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01']);

    expect($renewed->type)->toBe('indefinido');
});

// ─── Renovaciones previas (Art. 53 CLT) ─────────────────────────────────────

it('la primera renovación de un plazo fijo deja renewals_count en 1', function () {
    Auth::login(User::factory()->create());

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->type)->toBe('plazo_fijo')
        ->and($renewed->renewals_count)->toBe(1);
});

it('un plazo fijo cargado a mano con renovaciones previas declaradas pasa a indefinido al renovar', function () {
    Auth::login(User::factory()->create());

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');
    $contract->update(['renewals_count' => 1]);

    expect($contract->fresh()->wouldBecomeIndefiniteOnRenewal())->toBeTrue();

    $renewed = ContractService::renew($contract->fresh(), ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->type)->toBe('indefinido')
        ->and($renewed->end_date)->toBeNull();
});

it('un plazo fijo sin renovaciones previas no pasa a indefinido', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    expect($contract->wouldBecomeIndefiniteOnRenewal())->toBeFalse()
        ->and($contract->effectiveRenewals())->toBe(0);
});

it('effectiveRenewals toma el mayor entre lo declarado y los contratos Renovado del empleado', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    makeContract($employee, $posId, $deptId, type: 'plazo_fijo', status: 'renewed');
    $active = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    expect($active->effectiveRenewals())->toBe(1);

    $active->update(['renewals_count' => 2]);

    expect($active->fresh()->effectiveRenewals())->toBe(2);
});

// ─── Formulario de renovación ───────────────────────────────────────────────

it('la renovación propone iniciar el día siguiente al fin del contrato anterior', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, endDate: '2026-10-12');

    expect(ContractRenewalForm::defaultStartDate($contract)->toDateString())->toBe('2026-10-13');
});

it('la renovación de un contrato sin fecha de fin propone iniciar hoy', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'indefinido', endDate: null);

    expect(ContractRenewalForm::defaultStartDate($contract)->toDateString())->toBe(Carbon::today()->toDateString());
});

it('la renovación valida la duración máxima de un año en plazo fijo', function (string $type, string $start, string $end, bool $valid) {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: $type);

    $error = ContractRenewalForm::endDateError($contract, $start, $end);

    expect($error === null)->toBe($valid);
})->with([
    'plazo fijo de un año exacto' => ['plazo_fijo', '2026-10-13', '2027-10-13', true],
    'plazo fijo de seis meses' => ['plazo_fijo', '2026-10-13', '2027-04-13', true],
    'plazo fijo de más de un año' => ['plazo_fijo', '2026-10-13', '2027-10-14', false],
    'aprendizaje de más de un año' => ['aprendizaje', '2026-10-13', '2027-12-01', false],
    'fin igual al inicio' => ['plazo_fijo', '2026-10-13', '2026-10-13', false],
    'fin anterior al inicio' => ['plazo_fijo', '2026-10-13', '2026-09-01', false],
    'obra determinada no tiene tope de un año' => ['obra_determinada', '2026-10-13', '2028-10-13', true],
]);

it('la renovación no valida la fecha de fin si falta el inicio o el fin', function () {
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    expect(ContractRenewalForm::endDateError($contract, null, '2027-10-13'))->toBeNull()
        ->and(ContractRenewalForm::endDateError($contract, '2026-10-13', null))->toBeNull();
});

it('la renovación permite el mismo salario bajo el mínimo pero bloquea fijar uno nuevo bajo el mínimo', function () {
    foreach (['min_salary_monthly' => 3_044_000, 'min_salary_daily_jornal' => 101_467] as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'payroll', 'name' => $name],
            ['payload' => json_encode($value), 'locked' => false],
        );
    }

    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId);

    expect($contract->salary)->toBe(2_550_000)
        ->and(ContractRenewalForm::salaryError($contract, 2_550_000))->toBeNull()
        ->and(ContractRenewalForm::salaryError($contract, 2_800_000))->toBeString()
        ->and(ContractRenewalForm::salaryError($contract, 3_044_000))->toBeNull()
        ->and(ContractRenewalForm::salaryError($contract, 3_500_000))->toBeNull()
        ->and(ContractRenewalForm::salaryHint($contract))->toContain('por debajo del mínimo legal');
});

// ─── Excepción al Art. 53 (pasar a indefinido en la segunda renovación) ─────

/**
 * Enciende o apaga el interruptor de Configuración General que convierte a indefinido la segunda renovación.
 */
function setForceIndefiniteOnSecondRenewal(bool $enabled): void
{
    DB::table('settings')->updateOrInsert(
        ['group' => 'general', 'name' => 'contract_force_indefinite_on_second_renewal'],
        ['payload' => json_encode($enabled), 'locked' => false],
    );
}

/**
 * Crea un plazo fijo que ya fue renovado una vez (la próxima renovación sería la segunda).
 */
function makeRenewedOncePlazoFijo(): Contract
{
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');
    $contract->update(['renewals_count' => 1]);

    return $contract->fresh();
}

it('con el interruptor apagado la segunda renovación no pasa a indefinido', function () {
    Auth::login(User::factory()->create());
    setForceIndefiniteOnSecondRenewal(false);
    $contract = makeRenewedOncePlazoFijo();

    expect($contract->wouldBecomeIndefiniteOnRenewal())->toBeFalse();

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->type)->toBe('plazo_fijo')
        ->and($renewed->end_date->toDateString())->toBe('2026-12-31')
        ->and($renewed->renewals_count)->toBe(2);
});

it('con el interruptor encendido (por defecto) la segunda renovación pasa a indefinido', function () {
    Auth::login(User::factory()->create());
    $contract = makeRenewedOncePlazoFijo();

    expect($contract->wouldBecomeIndefiniteOnRenewal())->toBeTrue();

    $renewed = ContractService::renew($contract, ['start_date' => '2026-07-01', 'end_date' => '2026-12-31']);

    expect($renewed->type)->toBe('indefinido')
        ->and($renewed->end_date)->toBeNull();
});

it('la excepción mantiene el plazo fijo y deja el motivo en las notas', function () {
    Auth::login(User::factory()->create());
    $contract = makeRenewedOncePlazoFijo();

    $renewed = ContractService::renew($contract, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-12-31',
        'keep_fixed_term' => true,
        'keep_fixed_term_reason' => 'Reemplazo de una licencia por maternidad',
        'notes' => 'Renovación acordada',
    ]);

    expect($renewed->type)->toBe('plazo_fijo')
        ->and($renewed->end_date->toDateString())->toBe('2026-12-31')
        ->and($renewed->notes)->toContain('Renovación acordada')
        ->and($renewed->notes)->toContain('excepción al Art. 53')
        ->and($renewed->notes)->toContain('Reemplazo de una licencia por maternidad');
});

it('la excepción sin motivo no renueva y deja el contrato original como estaba', function () {
    Auth::login(User::factory()->create());
    $contract = makeRenewedOncePlazoFijo();

    expect(fn () => ContractService::renew($contract, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-12-31',
        'keep_fixed_term' => true,
        'keep_fixed_term_reason' => '   ',
    ]))->toThrow(InvalidArgumentException::class);

    expect($contract->fresh()->status)->toBe('active')
        ->and(Contract::where('employee_id', $contract->employee_id)->count())->toBe(1);
});

it('la excepción no deja el campo de control guardado ni afecta una renovación que no pasa a indefinido', function () {
    Auth::login(User::factory()->create());
    ['employee' => $employee, 'position_id' => $posId, 'department_id' => $deptId] = makeContractEmployee();
    $contract = makeContract($employee, $posId, $deptId, type: 'plazo_fijo');

    $renewed = ContractService::renew($contract, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-12-31',
        'keep_fixed_term' => true,
        'keep_fixed_term_reason' => 'No aplica',
    ]);

    expect($renewed->type)->toBe('plazo_fijo')
        ->and((string) $renewed->notes)->not->toContain('excepción al Art. 53');
});
