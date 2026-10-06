<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Hoja de instalación imprimible de un terminal: instrucciones y QR de la URL
 * del terminal para que el personal del local configure el dispositivo. No
 * incluye ningún secreto (ni enlace de configuración ni códigos de vinculación).
 */
class TerminalInstallSheetController extends Controller
{
    /** Muestra la hoja de instalación en el navegador (PDF inline). */
    public function show(Terminal $terminal): mixed
    {
        Gate::authorize('view', $terminal);

        $terminal->load('branch.company');
        $company = $terminal->branch?->company;
        $logoPath = $company?->logo ? storage_path('app/public/'.$company->logo) : null;

        $pdf = Pdf::loadView('pdf.terminal-install-sheet', [
            'terminal' => $terminal,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::size(180)->generate($terminal->url)),
            'companyLogo' => $logoPath && file_exists($logoPath) ? $logoPath : null,
            'companyName' => $company?->name ?? '',
            'windowMinutes' => Terminal::LINK_WINDOW_MINUTES,
        ])->setPaper('a4', 'portrait');

        $response = $pdf->stream("hoja_instalacion_terminal_{$terminal->code}.pdf");
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
