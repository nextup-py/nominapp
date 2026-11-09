<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Renderiza el detalle del reporte de asistencias con una jornada que tiene los eventos dados.
 *
 * @param  array<int, array{0: string, 1: string}>  $events
 */
function renderAttendanceReport(array $events): string
{
    $company = Company::create(['name' => 'EmpRB', 'ruc' => '4800001-1', 'employer_number' => 4800001]);
    $branch = Branch::create(['name' => 'SucRB', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Rep', 'last_name' => 'Descanso', 'ci' => '4800001', 'email' => 'rb@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => '2026-11-03', 'status' => 'present',
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse("2026-11-03 {$time}"),
        ]);
    }

    $detail = AttendanceDay::query()
        ->with(['employee.branch', 'events' => fn ($q) => $q->whereIn('event_type', ['break_start', 'break_end'])->orderBy('recorded_at')->orderBy('id')])
        ->get()
        ->groupBy('employee_id');

    return view('pdf.attendance-report-attendance', [
        'summary' => collect(), 'detail' => $detail, 'from' => '2026-11-01', 'to' => '2026-11-30',
        'companyLogo' => null, 'companyName' => 'EmpRB', 'companyRuc' => '', 'companyAddress' => '',
        'columns' => [], 'orientation' => 'landscape',
    ])->render();
}

it('muestra los descansos de cada jornada en el detalle del reporte', function () {
    $html = renderAttendanceReport([
        ['check_in', '07:00'], ['break_start', '09:00'], ['break_end', '09:15'],
        ['break_start', '12:00'], ['break_end', '12:45'], ['check_out', '16:00'],
    ]);

    expect($html)->toContain('Descansos')->toContain('09:00–09:15')->toContain('12:00–12:45');
});

it('marca con signo de pregunta un descanso sin cierre', function () {
    $html = renderAttendanceReport([['check_in', '07:00'], ['break_start', '12:00']]);

    expect($html)->toContain('12:00–?');
});
