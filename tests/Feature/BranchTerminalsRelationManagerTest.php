<?php

use App\Filament\Resources\BranchResource\Pages\ViewBranch;
use App\Filament\Resources\BranchResource\RelationManagers\TerminalsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeBranchForTerminalsRm(): Branch
{
    static $n = 7700000;
    $n++;

    $company = Company::create(['name' => "Empresa BranchRM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);

    return Branch::create(['name' => "Sucursal BranchRM {$n}", 'company_id' => $company->id]);
}

it('el RelationManager de terminales renderiza en la ficha de la sucursal', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $branch = makeBranchForTerminalsRm();
    Terminal::create(['name' => 'Terminal Branch RM', 'branch_id' => $branch->id]);

    Livewire::test(TerminalsRelationManager::class, [
        'ownerRecord' => $branch,
        'pageClass' => ViewBranch::class,
    ])->assertOk();
});

it('el RelationManager de terminales solo muestra los terminales de esa sucursal, no de otras', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $branch = makeBranchForTerminalsRm();
    $otherBranch = makeBranchForTerminalsRm();

    $ownTerminal = Terminal::create(['name' => 'Terminal Propio', 'branch_id' => $branch->id]);
    Terminal::create(['name' => 'Terminal Ajeno', 'branch_id' => $otherBranch->id]);

    $component = Livewire::test(TerminalsRelationManager::class, [
        'ownerRecord' => $branch,
        'pageClass' => ViewBranch::class,
    ]);

    $component->assertCanSeeTableRecords([$ownTerminal])
        ->assertCountTableRecords(1);
});

it('el RelationManager de terminales es de solo lectura', function () {
    $manager = new TerminalsRelationManager;

    expect($manager->isReadOnly())->toBeTrue();
});
