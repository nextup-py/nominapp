<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrgChartRequest;
use App\Models\Company;
use App\Services\OrgChartService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

class OrgChartController extends Controller
{
    public function __construct(private readonly OrgChartService $orgChart) {}

    /**
     * Muestra el organigrama de una empresa con los filtros de la URL (sucursal, departamento, búsqueda y vacantes).
     */
    public function show(OrgChartRequest $request, Company $company): View
    {
        $orgData = $this->orgChart->build($company, $request->filters());

        return view('org-chart.show', [
            'company' => $company,
            'orgData' => $orgData,
            'showBranch' => $orgData['showBranch'],
            'filters' => $request->filters(),
            'branches' => $company->branches()->orderBy('name')->pluck('name', 'id'),
            'departments' => $company->departments()->orderBy('name')->pluck('name', 'id'),
            'pdfParams' => $request->queryParams(),
        ]);
    }

    /**
     * Exporta a PDF el organigrama tal como se ve con los filtros activos.
     */
    public function exportPdf(OrgChartRequest $request, Company $company): Response
    {
        $logoPath = $company->logo;
        $companyLogo = $logoPath ? storage_path('app/public/'.$logoPath) : null;
        $companyLogo = $companyLogo && file_exists($companyLogo) ? $companyLogo : null;

        $pdf = Pdf::loadView('org-chart.pdf', [
            'company' => $company,
            'orgData' => $this->orgChart->build($company, $request->filters()),
            'companyLogo' => $companyLogo,
            'filterSummary' => $this->filterSummary($company, $request->filters()),
        ])->setPaper('a4', 'landscape');

        $response = $pdf->stream("organigrama-{$company->ruc}.pdf");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Texto con los filtros aplicados, para dejar constancia en el PDF (vacío si no hay ninguno).
     *
     * @param  array{branch: ?int, department: ?int, search: ?string, vacancies: bool}  $filters
     */
    private function filterSummary(Company $company, array $filters): string
    {
        return collect([
            $filters['branch'] ? 'Sucursal: '.$company->branches()->whereKey($filters['branch'])->value('name') : null,
            $filters['department'] ? 'Departamento: '.$company->departments()->whereKey($filters['department'])->value('name') : null,
            $filters['search'] ? 'Búsqueda: "'.$filters['search'].'"' : null,
            $filters['vacancies'] ? null : 'Sin vacantes',
        ])->filter()->implode(' · ');
    }
}
