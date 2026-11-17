<?php

namespace App\Services;

use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Genera el legajo del empleado en formato PDF para visualización en línea.
 * Incluye datos personales, laborales, contrato activo y deducciones/percepciones activas.
 */
class LegajoPDFGenerator
{
    /**
     * Genera el PDF del legajo y lo retorna como respuesta inline (se abre en el navegador).
     */
    public function download(Employee $employee): Response
    {
        $company = $employee->company;
        $logoPath = $company?->logo;

        $employee->load([
            'activeContract.position.department',
            'branch.company',
            'activeEmployeeDeductions.deduction',
            'activeEmployeePerceptions.perception',
        ]);

        $pdf = Pdf::loadView('pdf.legajo', [
            'employee' => $employee,
            'contract' => $employee->activeContract,
            'showSalary' => auth()->user()?->can('view_any_contract') ?? false,
            'companyLogo' => $logoPath ? storage_path('app/public/'.$logoPath) : null,
            'companyName' => $company?->name ?? '',
            'companyRuc' => $company?->ruc ?? '',
            'companyAddress' => $company?->address ?? '',
            'companyPhone' => $company?->phone ?? '',
            'companyEmail' => $company?->email ?? '',
            'employerNumber' => $company?->employer_number ?? '',
            'city' => $company?->city ?? '',
        ])->setPaper('A4');

        $slug = Str::slug($employee->full_name, '_');
        $fileName = 'legajo_'.$employee->id.'_'.$slug.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }
}
