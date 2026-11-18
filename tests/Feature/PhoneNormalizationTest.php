<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

it('normaliza los formatos habituales', function (?string $raw, ?string $expected) {
    expect(PhoneNormalizer::normalize($raw))->toBe($expected);
})->with([
    'ya normal' => ['0981123456', '0981123456'],
    'espacios' => ['0981 123 456', '0981123456'],
    'guiones y paréntesis' => ['(0981) 123-456', '0981123456'],
    'sin el 0 inicial' => ['981123456', '0981123456'],
    'con +595' => ['+595 981 123456', '0981123456'],
    'con 595 pegado' => ['595981123456', '0981123456'],
    'fijo de Asunción sin 0' => ['21123456', '021123456'],
    'fijo con 0' => ['021123456', '021123456'],
    'vacío' => ['  ', null],
    'null' => [null, null],
    'incompleto no se inventa' => ['98112345', '98112345'],
]);

it('valida el formato', function () {
    expect(PhoneNormalizer::isValid('0981123456'))->toBeTrue()
        ->and(PhoneNormalizer::isValid('021123456'))->toBeTrue()
        ->and(PhoneNormalizer::isValid('981123456'))->toBeFalse()
        ->and(PhoneNormalizer::isValid(null))->toBeFalse();
});

function phoneCompany(array $attrs = []): Company
{
    static $n = 7000;
    $n++;

    return Company::factory()->create($attrs + ['ruc' => "{$n}-1", 'employer_number' => $n]);
}

function phoneBranch(array $attrs = []): Branch
{
    static $n = 0;
    $n++;

    return Branch::create($attrs + ['name' => "Sucursal tel {$n}", 'company_id' => phoneCompany()->id]);
}

it('los modelos guardan el teléfono normalizado', function () {
    $branch = phoneBranch(['phone' => '0981 123-456']);
    $company = phoneCompany(['phone' => '+595 21 123456']);

    expect($branch->fresh()->phone)->toBe('0981123456')
        ->and($company->fresh()->phone)->toBe('021123456');
});

it('el comando corrige lo guardado y lista lo que sigue inválido', function () {
    $ok = phoneBranch();
    $bad = phoneBranch();
    DB::table('branches')->where('id', $ok->id)->update(['phone' => '981 123456']);
    DB::table('branches')->where('id', $bad->id)->update(['phone' => '98112']);

    $this->artisan('phones:normalize --dry-run')->assertSuccessful();
    expect($ok->fresh()->phone)->toBe('981 123456');

    $this->artisan('phones:normalize')->assertSuccessful();

    expect($ok->fresh()->phone)->toBe('0981123456')
        ->and($bad->fresh()->phone)->toBe('98112');
});

it('el empleado normaliza el teléfono al sanear el formulario', function () {
    expect(Employee::sanitizeFormData(['phone' => '981 123456'])['phone'])->toBe('0981123456');
});
