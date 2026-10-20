<?php

use App\Http\Controllers\MobileLinkController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(fn () => enableMobileMarking());

/**
 * Regresión: mismo gotcha que Terminal::claimSanctumToken() /
 * Employee::claimMobileToken() — un fallo del mailer no debe convertir una
 * operación ya persistida en un error de cliente. Ver
 * FaceEnrollmentController::store() y MobileLinkController::notifyAdminsOfDailyLimitOnce().
 */
function makeNotifyFailureEmployee(): Employee
{
    static $ci = 8500000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Notify {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Notify {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Notify {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Notify {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Notify',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

it('la captura facial por auto-registro se guarda aunque falle el envío de la notificación por email', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => null]);
    User::create(['name' => 'Admin', 'email' => 'admin-facemail@test.com', 'password' => bcrypt('secret')]);

    $employee = makeNotifyFailureEmployee();
    $enrollment = FaceEnrollment::createForEmployee($employee, User::factory()->create()->id, 24);

    $response = $this->postJson("/registro-facial/{$enrollment->token}", [
        'face_descriptor' => array_fill(0, 128, 0.1),
        'samples_count' => 5,
        'face_score' => 0.9,
    ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect($enrollment->fresh()->status)->toBe('pending_approval')
        ->and($enrollment->fresh()->face_descriptor)->not->toBeNull();
});

it('el bloqueo por límite diario de vinculación responde 429 aunque falle el envío de la notificación por email', function () {
    config(['mail.default' => 'resend', 'services.resend.key' => null]);
    User::create(['name' => 'Admin', 'email' => 'admin-linkmail@test.com', 'password' => bcrypt('secret')]);

    $request = Request::create('/vincular-dispositivo', 'POST', ['ci' => '9999999']);
    $request->server->set('REMOTE_ADDR', '203.0.113.55');

    $exception = new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 60,
        'X-RateLimit-Limit' => 15,
        'X-RateLimit-Remaining' => 0,
    ]);

    $response = MobileLinkController::throttledResponse($exception, $request);

    expect($response->getStatusCode())->toBe(429)
        ->and($response->getData(true)['ok'])->toBeFalse()
        ->and($response->getData(true)['message'])->toContain('límite');
});
