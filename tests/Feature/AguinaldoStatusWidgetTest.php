<?php

use App\Filament\Widgets\AguinaldoStatusWidget;
use App\Models\Aguinaldo;
use App\Models\AguinaldoPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeWidgetAguPeriod(string $status = 'draft'): AguinaldoPeriod
{
    static $n = 8700000;
    $n++;

    $company = Company::create(['name' => "Empresa Widget Agu {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);

    return AguinaldoPeriod::create(['company_id' => $company->id, 'year' => 2026, 'status' => $status]);
}

function addWidgetAguinaldo(AguinaldoPeriod $period, float $amount, string $status = 'pending'): Aguinaldo
{
    static $ci = 8800000;
    $n = $ci++;

    $branch = Branch::create(['name' => "Sucursal Widget Agu {$n}", 'company_id' => $period->company_id]);
    $employee = Employee::create([
        'first_name' => 'Widget', 'last_name' => "Agu {$n}", 'ci' => (string) $n,
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    return Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => $employee->id,
        'total_earned' => $amount * 12,
        'months_worked' => 12,
        'aguinaldo_amount' => $amount,
        'status' => $status,
    ]);
}

/** Valores de las tarjetas del widget, en orden. */
function aguinaldoWidgetStats($test): array
{
    return collect((fn () => $this->getStats())->call($test->instance()))
        ->map(fn ($stat) => $stat->getValue())
        ->all();
}

beforeEach(fn () => $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])))));

// ─── Tests ──────────────────────────────────────────────────────────────────

/**
 * Regresión: el widget sumaba `amount`, una columna que no existe en `aguinaldos`
 * (la real es `aguinaldo_amount`), así que el escritorio devolvía 500 apenas había
 * un período de aguinaldo con empleados.
 */
it('el widget renderiza sin error cuando el período tiene aguinaldos', function () {
    $period = makeWidgetAguPeriod();
    addWidgetAguinaldo($period, 1_000_000);

    Livewire::test(AguinaldoStatusWidget::class)->assertOk()->assertSee('Período de Aguinaldo');
});

it('cuenta los aguinaldos y los pendientes, y suma solo el monto pendiente de pago', function () {
    $period = makeWidgetAguPeriod('processing');
    addWidgetAguinaldo($period, 1_000_000, 'pending');
    addWidgetAguinaldo($period, 2_500_000, 'pending');
    addWidgetAguinaldo($period, 4_000_000, 'paid'); // ya pagado: no entra en "Total a Pagar"

    $stats = aguinaldoWidgetStats(Livewire::test(AguinaldoStatusWidget::class));

    expect($stats)->toBe(['2026', 3, 2, 'Gs. 3.500.000']);
});

it('con todos los aguinaldos pagados el total a pagar es cero', function () {
    $period = makeWidgetAguPeriod();
    addWidgetAguinaldo($period, 1_000_000, 'paid');

    $test = Livewire::test(AguinaldoStatusWidget::class);

    expect(aguinaldoWidgetStats($test))->toBe(['2026', 1, 0, 'Gs. 0']);
    $test->assertSee('Todos los aguinaldos pagados');
});

it('un período sin aguinaldos generados muestra todo en cero', function () {
    makeWidgetAguPeriod();

    expect(aguinaldoWidgetStats(Livewire::test(AguinaldoStatusWidget::class)))->toBe(['2026', 0, 0, 'Gs. 0']);
});

it('solo es visible con un período en borrador o procesamiento', function () {
    expect(AguinaldoStatusWidget::canView())->toBeFalse();

    $period = makeWidgetAguPeriod('closed');
    expect(AguinaldoStatusWidget::canView())->toBeFalse();

    $period->update(['status' => 'processing']);
    expect(AguinaldoStatusWidget::canView())->toBeTrue();
});

it('muestra el estado del período con la misma etiqueta que el resto del sistema ("En Proceso")', function () {
    makeWidgetAguPeriod('processing');

    Livewire::test(AguinaldoStatusWidget::class)->assertSee('Estado: En Proceso')->assertDontSee('En procesamiento');
});
