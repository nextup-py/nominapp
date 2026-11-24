<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('ninguna columna timestamp se reescribe sola en cada UPDATE', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Solo aplica a MySQL/MariaDB.');
    }

    $affected = DB::select(
        "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND EXTRA LIKE '%on update%'"
    );

    expect(collect($affected)->pluck('name')->all())->toBe([]);
});

it('cerrar un dispositivo del terminal conserva su linked_at original', function () {
    $company = Company::create(['name' => 'Empresa Timestamp', 'ruc' => '7990001-1', 'employer_number' => 7990001]);
    $branch = Branch::create(['name' => 'Sucursal Timestamp', 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => 'Terminal Timestamp', 'branch_id' => $branch->id]);
    $linkedAt = now()->subDays(3)->startOfSecond();

    $device = $terminal->devices()->create(['device_id' => 'abc12345', 'linked_via' => 'pairing', 'linked_at' => $linkedAt]);
    $device->forceFill(['unlinked_at' => now(), 'unlink_reason' => TerminalDevice::REASON_REPLACED])->save();

    expect($device->fresh()->linked_at->equalTo($linkedAt))->toBeTrue();
});
