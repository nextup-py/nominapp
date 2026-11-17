<?php

use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\DocumentTypeResource\Pages\ManageDocumentTypes;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\DocumentsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentsExpiringNotification;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado con empresa y sucursal propias. */
function docEmployee(string $status = 'active'): Employee
{
    static $n = 9100000;
    $n++;
    $company = Company::create(['name' => "EmpDoc {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucDoc {$n}", 'company_id' => $company->id]);

    return Employee::create(['first_name' => 'Ana', 'last_name' => "Doc{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => $status]);
}

/** Usuario con solo los permisos indicados (Super Admin con `null`). */
function docUser(?array $permissions = null): User
{
    $user = User::factory()->create();

    if ($permissions === null) {
        return tap($user, fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
    }

    $user->assignRole(Role::firstOrCreate(['name' => 'DocRol', 'guard_name' => 'web']));
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

function docOf(Employee $employee, array $attrs = []): Document
{
    return Document::create(array_merge([
        'employee_id' => $employee->id, 'name' => 'Cédula', 'file_path' => 'documents/cedula.pdf', 'disk' => 'local',
    ], $attrs));
}

it('el estado de vencimiento distingue sin fecha, vigente, por vencer y vencido', function () {
    $employee = docEmployee();

    expect(docOf($employee)->expiryStatus(30))->toBe('none')
        ->and(docOf($employee, ['expires_at' => today()->addDays(90)])->expiryStatus(30))->toBe('valid')
        ->and(docOf($employee, ['expires_at' => today()->addDays(10)])->expiryStatus(30))->toBe('expiring')
        ->and(docOf($employee, ['expires_at' => today()->subDay()])->expiryStatus(30))->toBe('expired')
        ->and(docOf($employee, ['expires_at' => today()])->expiryStatus(30))->toBe('expiring');

    expect(Document::expired()->count())->toBe(1)->and(Document::expiringWithin(30)->count())->toBe(2);
});

it('sube un documento privado con tipo y vencimiento y registra quién lo subió', function () {
    Storage::fake('local');
    $employee = docEmployee();
    $type = DocumentType::create(['company_id' => $employee->branch->company_id, 'name' => 'Carné de salud', 'requires_expiry' => true]);
    $user = docUser();
    $this->actingAs($user);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('create', data: [
            'document_type_id' => $type->id,
            'name' => 'Carné 2026',
            'expires_at' => today()->addYear()->toDateString(),
            'file_path' => UploadedFile::fake()->create('carne.pdf', 100, 'application/pdf'),
        ])
        ->assertHasNoTableActionErrors();

    $document = Document::first();

    expect($document->disk)->toBe('local')
        ->and($document->uploaded_by_id)->toBe($user->id)
        ->and($document->document_type_id)->toBe($type->id)
        ->and($document->expires_at->toDateString())->toBe(today()->addYear()->toDateString());
    Storage::disk('local')->assertExists($document->file_path);
});

it('un tipo con vencimiento exige la fecha', function () {
    Storage::fake('local');
    $employee = docEmployee();
    $type = DocumentType::create(['company_id' => $employee->branch->company_id, 'name' => 'Carné', 'requires_expiry' => true]);
    $this->actingAs(docUser());

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('create', data: [
            'document_type_id' => $type->id, 'name' => 'Carné',
            'file_path' => UploadedFile::fake()->create('carne.pdf', 10, 'application/pdf'),
        ])
        ->assertHasTableActionErrors(['expires_at' => 'required']);
});

it('solo ofrece los tipos activos de la empresa del empleado', function () {
    $employee = docEmployee();
    $other = docEmployee();
    DocumentType::create(['company_id' => $employee->branch->company_id, 'name' => 'Propio']);
    DocumentType::create(['company_id' => $employee->branch->company_id, 'name' => 'Inactivo', 'is_active' => false]);
    DocumentType::create(['company_id' => $other->branch->company_id, 'name' => 'Ajeno']);
    $this->actingAs(docUser());

    $manager = Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])->instance();
    $method = new ReflectionMethod($manager, 'typeOptions');
    $options = $method->invoke($manager, null);

    expect(array_values($options))->toBe(['Propio']);
});

it('la descarga exige permiso y sirve el archivo privado sin URL pública', function () {
    Storage::fake('local');
    Storage::disk('local')->put('documents/cedula.pdf', 'contenido');
    $document = docOf(docEmployee());

    $this->actingAs(docUser(['view_any_employee']))->get(route('documents.download', $document))->assertForbidden();

    $this->actingAs(docUser(['view_document']))->get(route('documents.download', $document))->assertOk();

    Storage::disk('local')->delete('documents/cedula.pdf');
    $this->actingAs(docUser(['view_document']))->get(route('documents.download', $document))->assertNotFound();
});

it('el catálogo de tipos se gestiona con permiso y no repite nombres en la empresa', function () {
    $company = Company::create(['name' => 'EmpTipos', 'ruc' => '9200000-1', 'employer_number' => 9200000]);
    $this->actingAs(docUser());

    Livewire::test(ManageDocumentTypes::class)
        ->callAction('create', data: ['company_id' => $company->id, 'name' => 'Cédula', 'requires_expiry' => false, 'is_active' => true])
        ->assertHasNoActionErrors()
        ->callAction('create', data: ['company_id' => $company->id, 'name' => 'Cédula', 'requires_expiry' => false, 'is_active' => true])
        ->assertHasActionErrors(['name' => 'unique']);

    expect(DocumentType::count())->toBe(1);

    $this->actingAs(docUser(['view_any_employee']));
    expect(DocumentTypeResource::canViewAny())->toBeFalse();
    $this->actingAs(docUser(['view_any_document_type']));
    expect(DocumentTypeResource::canViewAny())->toBeTrue();
});

it('documents:notify-expiring avisa una vez por documento y estado, solo de activos y solo a quien tiene permiso', function () {
    $active = docEmployee();
    $inactive = docEmployee('inactive');
    $expired = docOf($active, ['name' => 'Carné', 'expires_at' => today()->subDays(2)]);
    $expiring = docOf($active, ['name' => 'Cédula', 'expires_at' => today()->addDays(10)]);
    docOf($active, ['name' => 'Lejano', 'expires_at' => today()->addDays(200)]);
    docOf($inactive, ['name' => 'De baja', 'expires_at' => today()->subDay()]);

    $hr = docUser(['view_any_document']);
    $other = docUser(['view_any_employee']);

    $this->artisan('documents:notify-expiring')->assertSuccessful();
    $this->artisan('documents:notify-expiring')->assertSuccessful();

    $notification = $hr->notifications()->where('type', DocumentsExpiringNotification::class)->first();

    expect($hr->notifications()->where('type', DocumentsExpiringNotification::class)->count())->toBe(1)
        ->and($other->notifications()->where('type', DocumentsExpiringNotification::class)->count())->toBe(0)
        ->and($notification->data['document_ids'])->toEqualCanonicalizing([$expired->id, $expiring->id]);

    // Al renovar la fecha, los avisos de ese documento vuelven a empezar.
    $expired->update(['expires_at' => today()->addDays(5)]);
    $this->artisan('documents:notify-expiring')->assertSuccessful();

    expect($hr->notifications()->where('type', DocumentsExpiringNotification::class)->count())->toBe(2);
});

it('con 0 días el aviso de documentos queda apagado', function () {
    docOf(docEmployee(), ['expires_at' => today()->subDay()]);
    $hr = docUser(['view_any_document']);
    $settings = app(GeneralSettings::class);
    $settings->document_alert_days = 0;
    $settings->save();

    $this->artisan('documents:notify-expiring')->assertSuccessful();

    expect($hr->notifications()->count())->toBe(0);
});
