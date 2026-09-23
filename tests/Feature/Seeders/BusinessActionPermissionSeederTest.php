<?php

use Database\Seeders\BusinessActionPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds all 43 business-action permissions', function () {
    (new BusinessActionPermissionSeeder)->run();

    expect(Permission::count())->toBe(43);

    expect(Permission::where('name', 'approve_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_loan')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'revert_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_advance')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_merchandise_withdrawal')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_merchandise_withdrawal')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_merchandise_withdrawal')->exists())->toBeTrue();

    expect(Permission::where('name', 'confirm_disbursement_batch')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_disbursement_batch')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'mark_paid_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'revert_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'regenerate_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_payroll')->exists())->toBeTrue();

    expect(Permission::where('name', 'generate_payrolls_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_payroll_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'reopen_payroll_period')->exists())->toBeTrue();

    expect(Permission::where('name', 'calculate_liquidacion')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_liquidacion')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_liquidacion')->exists())->toBeTrue();

    expect(Permission::where('name', 'mark_paid_aguinaldo')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_aguinaldo')->exists())->toBeTrue();

    expect(Permission::where('name', 'generate_aguinaldos_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_aguinaldo_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'reopen_aguinaldo_period')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_employee_leave')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_employee_leave')->exists())->toBeTrue();

    expect(Permission::where('name', 'justify_absence')->exists())->toBeTrue();
    expect(Permission::where('name', 'mark_unjustified_absence')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_absence')->exists())->toBeTrue();

    expect(Permission::where('name', 'activate_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'renew_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'suspend_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'reactivate_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'terminate_contract')->exists())->toBeTrue();
});

it('is idempotent — running twice does not duplicate permissions', function () {
    (new BusinessActionPermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();

    expect(Permission::count())->toBe(43);
});
