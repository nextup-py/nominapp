<?php

namespace App\Http\Controllers;

use App\Exports\PayrollSimulationExport;
use App\Services\PayrollSimulationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/** Descarga en Excel de una simulación de nómina (se recalcula con los mismos parámetros de la pantalla). */
class PayrollSimulationController extends Controller
{
    /**
     * Calcula la simulación y la devuelve como Excel con las hojas «Resumen» y «Detalle».
     */
    public function excel(Request $request, PayrollSimulationService $service): BinaryFileResponse|Response
    {
        $data = $request->validate([
            'company' => ['required', 'integer', 'exists:companies,id'],
            'frequency' => ['required', 'in:monthly,biweekly,weekly'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        try {
            $simulation = $service->simulate((int) $data['company'], $data['frequency'], Carbon::parse($data['from']), Carbon::parse($data['to']));
        } catch (\DomainException|\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return Excel::download(
            new PayrollSimulationExport($simulation),
            'simulacion_nomina_'.$data['from'].'_'.$data['to'].'.xlsx',
        );
    }
}
