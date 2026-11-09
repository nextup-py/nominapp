<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Company;
use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Genera los PDFs del reporte de asistencia: asistencias, ausencias y horas extras/tardanzas. */
class AttendancePdfReportController extends Controller
{
    /**
     * Genera el PDF del tab Asistencias: resumen por empleado + detalle día a día.
     */
    public function attendance(Request $request): Response
    {
        set_time_limit(120);
        [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->parseFilters($request);
        $columns = $this->parseColumns($request, ['ci', 'branch_name', 'department_name', 'days_present', 'days_absent', 'days_leave', 'total_net_hours', 'total_anomalies']);
        $orientation = $this->parseOrientation($request, 'landscape');

        $summary = Employee::query()
            ->select([
                'employees.id',
                'employees.last_name',
                'employees.first_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'present'  THEN 1 ELSE 0 END), 0) AS days_present"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'absent'   THEN 1 ELSE 0 END), 0) AS days_absent"),
                DB::raw("COALESCE(SUM(CASE WHEN ad.status = 'on_leave' THEN 1 ELSE 0 END), 0) AS days_leave"),
                DB::raw('ROUND(COALESCE(SUM(ad.net_hours), 0), 2) AS total_net_hours'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.anomaly_flag = 1 THEN 1 ELSE 0 END), 0) AS total_anomalies'),
            ])
            ->join('attendance_days as ad', 'ad.employee_id', '=', 'employees.id')
            ->when($from, fn ($q) => $q->where('ad.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('ad.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id')
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get();

        $detail = AttendanceDay::query()
            ->with([
                'employee',
                'employee.branch',
                'events' => fn ($q) => $q->whereIn('event_type', ['break_start', 'break_end'])->orderBy('recorded_at')->orderBy('id'),
            ])
            ->join('employees', 'employees.id', '=', 'attendance_days.employee_id')
            ->select('attendance_days.*')
            ->when($from, fn ($q) => $q->whereDate('attendance_days.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_days.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->orderBy('employees.last_name')->orderBy('employees.first_name')->orderBy('attendance_days.date')
            ->get()
            ->groupBy('employee_id');

        [, $companyLogo, $companyName, $companyRuc, $companyAddress] = $this->resolveCompany($companyId);

        $pdf = Pdf::loadView('pdf.attendance-report-attendance', compact(
            'summary', 'detail', 'from', 'to',
            'companyLogo', 'companyName', 'companyRuc', 'companyAddress',
            'columns', 'orientation',
        ))->setPaper('a4', $orientation);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="asistencias_'.now()->format('Ymd_His').'.pdf"',
        ]);
    }

    /**
     * Genera el PDF del tab Ausencias: resumen por empleado + detalle de cada ausencia.
     */
    public function absence(Request $request): Response
    {
        set_time_limit(120);
        [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->parseFilters($request);
        $columns = $this->parseColumns($request, ['ci', 'branch_name', 'department_name', 'total_absences', 'total_pending', 'total_justified', 'total_unjustified', 'total_deduction_amount']);
        $orientation = $this->parseOrientation($request, 'portrait');

        $summary = Employee::query()
            ->select([
                'employees.id',
                'employees.last_name',
                'employees.first_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw('COUNT(abs.id) AS total_absences'),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'pending'     THEN 1 ELSE 0 END), 0) AS total_pending"),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'justified'   THEN 1 ELSE 0 END), 0) AS total_justified"),
                DB::raw("COALESCE(SUM(CASE WHEN abs.status = 'unjustified' THEN 1 ELSE 0 END), 0) AS total_unjustified"),
                DB::raw('COALESCE(SUM(CASE WHEN abs.employee_deduction_id IS NOT NULL THEN ed.custom_amount ELSE 0 END), 0) AS total_deduction_amount'),
            ])
            ->join('absences as abs', 'abs.employee_id', '=', 'employees.id')
            ->join('attendance_days as ad', 'ad.id', '=', 'abs.attendance_day_id')
            ->leftJoin('employee_deductions as ed', 'ed.id', '=', 'abs.employee_deduction_id')
            ->when($from, fn ($q) => $q->where('ad.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('ad.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id')
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get();

        $detail = Absence::query()
            ->with(['employee', 'employee.branch', 'attendanceDay'])
            ->join('employees', 'employees.id', '=', 'absences.employee_id')
            ->join('attendance_days as ad', 'ad.id', '=', 'absences.attendance_day_id')
            ->select('absences.*')
            ->when($from, fn ($q) => $q->where('ad.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('ad.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->orderBy('employees.last_name')->orderBy('employees.first_name')->orderBy('ad.date')
            ->get()
            ->groupBy('employee_id');

        [, $companyLogo, $companyName, $companyRuc, $companyAddress] = $this->resolveCompany($companyId);

        $pdf = Pdf::loadView('pdf.attendance-report-absence', compact(
            'summary', 'detail', 'from', 'to',
            'companyLogo', 'companyName', 'companyRuc', 'companyAddress',
            'columns', 'orientation',
        ))->setPaper('a4', $orientation);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="ausencias_'.now()->format('Ymd_His').'.pdf"',
        ]);
    }

    /**
     * Genera el PDF del tab Horas Extras y Tardanzas: resumen por empleado + detalle día a día.
     */
    public function overtime(Request $request): Response
    {
        set_time_limit(120);
        [$from, $to, $companyId, $branchId, $deptId, $employeeId] = $this->parseFilters($request);
        $columns = $this->parseColumns($request, ['ci', 'branch_name', 'department_name', 'total_extra_hours', 'total_extra_diurnas', 'total_extra_nocturnas', 'days_with_extras', 'days_approved', 'total_late_minutes', 'days_late', 'avg_late_minutes']);
        $orientation = $this->parseOrientation($request, 'landscape');

        $summary = Employee::query()
            ->select([
                'employees.id',
                'employees.last_name',
                'employees.first_name',
                'employees.ci',
                DB::raw('(SELECT b.name FROM branches b WHERE b.id = employees.branch_id) AS branch_name'),
                DB::raw("(SELECT d.name FROM contracts c INNER JOIN positions p ON p.id = c.position_id INNER JOIN departments d ON d.id = p.department_id WHERE c.employee_id = employees.id AND c.status = 'active' ORDER BY c.start_date DESC LIMIT 1) AS department_name"),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours), 0), 2)             AS total_extra_hours'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_diurnas), 0), 2)     AS total_extra_diurnas'),
                DB::raw('ROUND(COALESCE(SUM(ad.extra_hours_nocturnas), 0), 2)   AS total_extra_nocturnas'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.extra_hours > 0 THEN 1 ELSE 0 END), 0)       AS days_with_extras'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.overtime_approved = 1 THEN 1 ELSE 0 END), 0) AS days_approved'),
                DB::raw('COALESCE(SUM(ad.late_minutes), 0)                                       AS total_late_minutes'),
                DB::raw('COALESCE(SUM(CASE WHEN ad.late_minutes > 0 THEN 1 ELSE 0 END), 0)      AS days_late'),
                DB::raw('ROUND(COALESCE(AVG(CASE WHEN ad.late_minutes > 0 THEN ad.late_minutes END), 0), 0) AS avg_late_minutes'),
            ])
            ->join('attendance_days as ad', 'ad.employee_id', '=', 'employees.id')
            ->where(fn ($q) => $q->where('ad.extra_hours', '>', 0)->orWhere('ad.late_minutes', '>', 0))
            ->when($from, fn ($q) => $q->where('ad.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('ad.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id')
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get();

        $detail = AttendanceDay::query()
            ->with(['employee', 'employee.branch'])
            ->join('employees', 'employees.id', '=', 'attendance_days.employee_id')
            ->select('attendance_days.*')
            ->where(fn ($q) => $q->where('attendance_days.extra_hours', '>', 0)->orWhere('attendance_days.late_minutes', '>', 0))
            ->when($from, fn ($q) => $q->whereDate('attendance_days.date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_days.date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($employeeId, fn ($q) => $q->where('employees.id', $employeeId))
            ->when($companyId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('branches')->whereColumn('branches.id', 'employees.branch_id')->where('branches.company_id', $companyId)))
            ->when($deptId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw(1)->from('contracts')->whereColumn('contracts.employee_id', 'employees.id')->where('contracts.status', 'active')->where('contracts.department_id', $deptId)))
            ->orderBy('employees.last_name')->orderBy('employees.first_name')->orderBy('attendance_days.date')
            ->get()
            ->groupBy('employee_id');

        [, $companyLogo, $companyName, $companyRuc, $companyAddress] = $this->resolveCompany($companyId);

        $pdf = Pdf::loadView('pdf.attendance-report-overtime', compact(
            'summary', 'detail', 'from', 'to',
            'companyLogo', 'companyName', 'companyRuc', 'companyAddress',
            'columns', 'orientation',
        ))->setPaper('a4', $orientation);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="extras_tardanzas_'.now()->format('Ymd_His').'.pdf"',
        ]);
    }

    /**
     * Extrae y tipifica los filtros de la request.
     *
     * @return array{string|null, string|null, int|null, int|null, int|null, int|null}
     */
    private function parseFilters(Request $request): array
    {
        return [
            $request->query('from') ?: null,
            $request->query('to') ?: null,
            $request->query('companyId') ? (int) $request->query('companyId') : null,
            $request->query('branchId') ? (int) $request->query('branchId') : null,
            $request->query('deptId') ? (int) $request->query('deptId') : null,
            $request->query('employeeId') ? (int) $request->query('employeeId') : null,
        ];
    }

    /**
     * Parsea las columnas seleccionadas desde el query string.
     * Si no se envían, retorna los defaults del tab.
     *
     * @param  array<int, string>  $defaults
     * @return array<int, string>
     */
    private function parseColumns(Request $request, array $defaults): array
    {
        $cols = $request->query('columns');

        return $cols ? explode(',', $cols) : $defaults;
    }

    /**
     * Parsea la orientación del PDF desde el query string.
     */
    private function parseOrientation(Request $request, string $fallback = 'landscape'): string
    {
        $o = $request->query('orientation');

        return in_array($o, ['portrait', 'landscape']) ? $o : $fallback;
    }

    /**
     * Resuelve los datos de la empresa para el encabezado del PDF.
     *
     * @return array{Company|null, string|null, string, string, string}
     */
    private function resolveCompany(?int $companyId): array
    {
        $company = $companyId ? Company::find($companyId) : null;
        if ($company === null && Company::active()->count() === 1) {
            $company = Company::first();
        }

        $logoPath = $company?->logo;
        $companyLogo = $logoPath ? storage_path('app/public/'.$logoPath) : null;
        $companyLogo = $companyLogo && file_exists($companyLogo) ? $companyLogo : null;

        return [
            $company,
            $companyLogo,
            $company?->name ?? '',
            $company?->ruc ?? '',
            $company?->address ?? '',
        ];
    }
}
