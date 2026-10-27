<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\PrivacyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => enableMobileMarking());

/** Empleado con rostro; la empresa puede traer o no un aviso de privacidad. */
function consentEmployee(array $company = []): Employee
{
    static $n = 9800000;
    $n++;

    $companyModel = Company::create(['name' => "EmpCN {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n, ...$company]);
    $branch = Branch::create(['company_id' => $companyModel->id, 'name' => "SucCN {$n}"]);

    return Employee::create([
        'branch_id' => $branch->id,
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'ci' => (string) $n,
        'birth_date' => '1990-05-20',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
    ]);
}

/** Enlace de registro facial vigente generado por un usuario. */
function enrollmentFor(Employee $employee): FaceEnrollment
{
    return FaceEnrollment::createForEmployee($employee, User::factory()->create()->id, 24);
}

/** Cuerpo válido del POST de autoenrolamiento. */
function enrollmentPayload(array $extra = []): array
{
    return ['face_descriptor' => array_fill(0, 128, 0.1), 'samples_count' => 5, 'face_score' => 0.9, ...$extra];
}

// ─── Vinculación del celular ─────────────────────────────────────────────────

it('sin aviso de privacidad vincula como siempre y no registra consentimiento', function () {
    $employee = consentEmployee();

    $this->postJson(route('device-link.claim'), ['ci' => $employee->ci, 'birth_date' => '1990-05-20'])
        ->assertSuccessful();

    expect(PrivacyConsent::count())->toBe(0);
});

it('con aviso pide aceptarlo antes de vincular y no emite token', function () {
    $employee = consentEmployee(['privacy_notice' => 'Usamos su rostro para asistencia.']);

    $this->postJson(route('device-link.claim'), ['ci' => $employee->ci, 'birth_date' => '1990-05-20'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'privacy_consent_required')
        ->assertJsonPath('privacy.version', 1)
        ->assertJsonPath('privacy.text', 'Usamos su rostro para asistencia.');

    expect($employee->fresh()->hasMobileLinked())->toBeFalse()
        ->and(PrivacyConsent::count())->toBe(0);
});

it('rechaza una versión del aviso que no es la vigente', function () {
    $employee = consentEmployee(['privacy_notice' => 'Texto']);

    $this->postJson(route('device-link.claim'), ['ci' => $employee->ci, 'birth_date' => '1990-05-20', 'accept_privacy_version' => 99])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'privacy_consent_required');
});

it('vincula y registra quién, cuándo, versión y copia del texto al aceptar', function () {
    $employee = consentEmployee(['privacy_notice' => 'Texto vigente', 'privacy_policy_url' => 'https://empresa.com.py/p']);

    $this->postJson(route('device-link.claim'), ['ci' => $employee->ci, 'birth_date' => '1990-05-20', 'accept_privacy_version' => 1])
        ->assertSuccessful();

    $consent = PrivacyConsent::sole();
    expect($consent->employee_id)->toBe($employee->id)
        ->and($consent->company_id)->toBe($employee->company->id)
        ->and($consent->context)->toBe(PrivacyConsent::CONTEXT_MOBILE_LINK)
        ->and($consent->notice_version)->toBe(1)
        ->and($consent->notice_text)->toBe('Texto vigente')
        ->and($consent->notice_url)->toBe('https://empresa.com.py/p')
        ->and($consent->ip_address)->not->toBeNull()
        ->and($consent->accepted_at)->not->toBeNull();
});

it('quien ya aceptó vincula de nuevo sin que se le pida otra vez', function () {
    $employee = consentEmployee(['privacy_notice' => 'Texto']);
    $payload = ['ci' => $employee->ci, 'birth_date' => '1990-05-20'];
    $this->postJson(route('device-link.claim'), $payload + ['accept_privacy_version' => 1])->assertSuccessful();

    $this->postJson(route('device-link.claim'), $payload)->assertSuccessful();

    expect(PrivacyConsent::count())->toBe(1);
});

// ─── Autoenrolamiento facial ─────────────────────────────────────────────────

it('sin aviso el autoenrolamiento sigue igual', function () {
    $employee = consentEmployee();
    $enrollment = enrollmentFor($employee);

    $this->postJson(route('face-enrollment.store', $enrollment->token), enrollmentPayload())->assertSuccessful();

    expect(PrivacyConsent::count())->toBe(0);
});

it('con aviso el autoenrolamiento exige aceptarlo y no guarda el rostro sin él', function () {
    $employee = consentEmployee(['privacy_notice' => 'Texto']);
    $enrollment = enrollmentFor($employee);

    $this->postJson(route('face-enrollment.store', $enrollment->token), enrollmentPayload())
        ->assertUnprocessable();

    expect($enrollment->fresh()->status)->toBe('pending_capture')
        ->and(PrivacyConsent::count())->toBe(0);
});

it('con aviso y aceptación guarda el rostro y registra el consentimiento', function () {
    $employee = consentEmployee(['privacy_notice' => 'Texto']);
    $enrollment = enrollmentFor($employee);

    $this->postJson(route('face-enrollment.store', $enrollment->token), enrollmentPayload(['privacy_consent_version' => 1]))
        ->assertSuccessful();

    $consent = PrivacyConsent::sole();
    expect($enrollment->fresh()->status)->toBe('pending_approval')
        ->and($consent->context)->toBe(PrivacyConsent::CONTEXT_FACE_ENROLLMENT)
        ->and($consent->employee_id)->toBe($employee->id);
});

it('la página de registro facial muestra el aviso y la casilla solo si la empresa lo cargó', function () {
    $con = consentEmployee(['privacy_notice' => 'Texto del aviso']);
    $sin = consentEmployee();

    $conPage = $this->get(route('face-enrollment.show', enrollmentFor($con)->token));
    $sinPage = $this->get(route('face-enrollment.show', enrollmentFor($sin)->token));

    $conPage->assertSee('Texto del aviso')->assertSee('privacyConsentCheck', false);
    $sinPage->assertDontSee('privacyConsentCheck', false);
});

// ─── Estado en Empleados ─────────────────────────────────────────────────────

it('calcula el estado del consentimiento frente a la versión vigente', function () {
    $sinAviso = consentEmployee();
    expect($sinAviso->privacyConsentStatus())->toBe('no_notice');

    $employee = consentEmployee(['privacy_notice' => 'v1']);
    expect($employee->privacyConsentStatus())->toBe('none');

    $consent = PrivacyConsent::record($employee, $employee->company, PrivacyConsent::CONTEXT_FACE_ENROLLMENT, $employee->company->privacyNoticePayload(), request());
    expect($employee->fresh()->privacyConsentStatus())->toBe('current');

    $employee->company->update(['privacy_notice' => 'v2 con cambios']);
    expect($employee->fresh()->privacyConsentStatus())->toBe('outdated')
        ->and($consent->notice_version)->toBe(1);
});

it('el filtro del listado separa vigentes, desactualizados y sin registro', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $vigente = consentEmployee(['privacy_notice' => 'a']);
    PrivacyConsent::record($vigente, $vigente->company, PrivacyConsent::CONTEXT_MOBILE_LINK, $vigente->company->privacyNoticePayload(), request());

    $viejo = consentEmployee(['privacy_notice' => 'b']);
    PrivacyConsent::record($viejo, $viejo->company, PrivacyConsent::CONTEXT_MOBILE_LINK, $viejo->company->privacyNoticePayload(), request());
    $viejo->company->update(['privacy_notice' => 'b modificado']);

    $nunca = consentEmployee(['privacy_notice' => 'c']);
    $sinAviso = consentEmployee();

    Livewire::test(ListEmployees::class)->filterTable('privacy_consent', 'current')
        ->assertCanSeeTableRecords([$vigente])->assertCanNotSeeTableRecords([$viejo, $nunca, $sinAviso]);
    Livewire::test(ListEmployees::class)->filterTable('privacy_consent', 'outdated')
        ->assertCanSeeTableRecords([$viejo])->assertCanNotSeeTableRecords([$vigente, $nunca, $sinAviso]);
    Livewire::test(ListEmployees::class)->filterTable('privacy_consent', 'none')
        ->assertCanSeeTableRecords([$nunca])->assertCanNotSeeTableRecords([$vigente, $viejo, $sinAviso]);
});
